<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Telegram\Services\TelegramLinkService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Profil')]
final class Profile extends Component
{
    public string $name = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $flash = null;

    /** One-time Telegram link code, shown once; only its hash is stored server-side. */
    #[Locked]
    public ?string $telegramCode = null;

    #[Locked]
    public ?string $telegramCodeExpiresAt = null;

    public function mount(): void
    {
        $this->name = $this->user()->name;
    }

    public function saveName(AuditLogger $audit): void
    {
        $this->validate(['name' => ['required', 'string', 'max:255']], [], ['name' => 'nama']);
        $user = $this->user();
        $user->update(['name' => trim($this->name)]);
        $audit->record('user.profile_updated', $user, $user, ['fields' => ['name']]);
        $this->flash = 'Nama diperbarui.';
    }

    /**
     * Changing the password signs out every other session and revokes all API tokens,
     * so a leaked password or token stops working immediately (ISO/IEC 27001 A.5.17 / A.8.5).
     */
    public function changePassword(AuditLogger $audit, TelegramLinkService $telegram): void
    {
        $this->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed', 'different:current_password'],
        ], [], ['current_password' => 'kata sandi saat ini', 'password' => 'kata sandi baru']);

        $user = $this->user();

        DB::transaction(function () use ($user): void {
            $user->forceFill(['password' => Hash::make($this->password)])->save();
            $user->tokens()->delete();

            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->where('id', '!=', session()->getId())
                    ->delete();
            }
        });

        session()->regenerate();
        $audit->record('auth.password_changed', $user, $user, ['channel' => 'WEB']);
        $unlinked = $telegram->unlink($user, 'password_changed');

        $this->reset(['current_password', 'password', 'password_confirmation', 'telegramCode', 'telegramCodeExpiresAt']);
        $this->flash = 'Kata sandi diganti. Sesi lain dan semua token API telah dicabut'.($unlinked ? ', dan Telegram diputus.' : '.');
    }

    public function createTelegramCode(TelegramLinkService $telegram): void
    {
        $key = 'telegram-code:'.$this->user()->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('telegram', 'Terlalu sering membuat kode. Coba lagi dalam beberapa menit.');

            return;
        }
        RateLimiter::hit($key, 600);

        $issued = $telegram->issueCode($this->user());
        $this->telegramCode = $issued['code'];
        $this->telegramCodeExpiresAt = CarbonImmutable::instance($issued['expires_at'])
            ->setTimezone((string) config('finance.display_timezone'))->format('H:i');
    }

    public function unlinkTelegram(TelegramLinkService $telegram): void
    {
        $telegram->unlink($this->user(), 'web');
        $this->reset(['telegramCode', 'telegramCodeExpiresAt']);
        $this->flash = 'Telegram diputus dari akun Anda.';
    }

    public function render(TelegramLinkService $telegram): View
    {
        $bot = (string) config('services.telegram.bot_username');

        return view('livewire.profile', [
            'email' => $this->user()->email,
            'telegramEnabled' => (string) config('services.telegram.bot_token') !== '' && (string) config('services.telegram.webhook_secret') !== '',
            'telegramLink' => $telegram->forUser($this->user()),
            'botUsername' => $bot,
            'telegramDeepLink' => $bot !== '' && $this->telegramCode !== null ? 'https://t.me/'.$bot.'?start='.$this->telegramCode : null,
        ]);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
