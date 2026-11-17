<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Telegram\Models\TelegramLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Links a Telegram private chat to a user with a one-time code shown on the web Profile page.
 * Codes: 8 chars from a 32-symbol alphabet, single use, short TTL, only their hash is cached,
 * and a new code invalidates the previous one. Failed attempts are rate-limited per chat.
 */
final readonly class TelegramLinkService
{
    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private AuditLogger $audit) {}

    /** @return array{code: string, expires_at: \DateTimeInterface} */
    public function issueCode(User $user): array
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $expiresAt = now()->addMinutes((int) config('services.telegram.link_ttl_minutes', 10));

        if (is_string($previous = Cache::get($this->userKey($user)))) {
            Cache::forget($this->codeKey($previous));
        }

        $hash = hash('sha256', $code);
        Cache::put($this->codeKey($hash), $user->id, $expiresAt);
        Cache::put($this->userKey($user), $hash, $expiresAt);

        $this->audit->record('telegram.link_code_issued', $user, $user);

        return ['code' => $code, 'expires_at' => $expiresAt];
    }

    /** Consumes the code. Returns the linked user, or null when the code is unknown/expired. */
    public function link(string $code, int $chatId): ?User
    {
        $hash = hash('sha256', strtoupper(trim($code)));
        $userId = Cache::pull($this->codeKey($hash));

        if ($userId === null || ($user = User::query()->find($userId)) === null) {
            return null;
        }

        Cache::forget($this->userKey($user));

        DB::transaction(function () use ($user, $chatId): void {
            // A chat belongs to one user and a user has one chat: re-linking moves it.
            TelegramLink::query()->where('chat_id', $chatId)->where('user_id', '!=', $user->id)->delete();
            TelegramLink::query()->updateOrCreate(['user_id' => $user->id], ['chat_id' => $chatId, 'linked_at' => now(), 'last_used_at' => now()]);
        });

        $this->audit->record('telegram.linked', $user, $user, ['channel' => 'TELEGRAM']);

        return $user;
    }

    public function unlink(User $user, string $reason): bool
    {
        $deleted = TelegramLink::query()->where('user_id', $user->id)->delete() > 0;

        if ($deleted) {
            $this->audit->record('telegram.unlinked', $user, $user, ['reason' => $reason]);
        }

        return $deleted;
    }

    public function forChat(int $chatId): ?TelegramLink
    {
        return TelegramLink::query()->with('user')->where('chat_id', $chatId)->first();
    }

    public function forUser(User $user): ?TelegramLink
    {
        return TelegramLink::query()->where('user_id', $user->id)->first();
    }

    private function codeKey(string $hash): string
    {
        return 'telegram:link-code:'.$hash;
    }

    private function userKey(User $user): string
    {
        return 'telegram:link-code-of:'.$user->id;
    }
}
