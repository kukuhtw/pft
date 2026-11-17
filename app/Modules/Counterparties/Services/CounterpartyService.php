<?php

declare(strict_types=1);

namespace App\Modules\Counterparties\Services;

use App\Models\User;
use App\Modules\Counterparties\Enums\CounterpartyKind;
use App\Modules\Counterparties\Models\Counterparty;
use Illuminate\Support\Str;

final class CounterpartyService
{
    /** Indonesian/English honorifics ignored when matching ("Pak Budi" == "budi"). */
    private const string HONORIFICS = '/^(?:pak|bu|bapak|ibu|mas|mbak|mba|kak|bang|om|tante|sdr|sdri|bro|sis|mr|mrs|ms)\.?\s+/u';

    public static function normalize(string $name): string
    {
        $n = Str::of($name)->lower()->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')->squish()->toString();
        $n = (string) preg_replace(self::HONORIFICS, '', $n);

        return Str::limit($n, 128, '');
    }

    /** Find or create a counterparty by name. Returns null for empty input. */
    public function resolve(User $user, ?string $name): ?Counterparty
    {
        $name = $name !== null ? Str::squish($name) : null;

        if ($name === null || $name === '' || ($normalized = self::normalize($name)) === '') {
            return null;
        }

        return Counterparty::query()->firstOrCreate(
            ['user_id' => $user->id, 'normalized_name' => $normalized],
            ['name' => Str::limit($name, 128, ''), 'kind' => CounterpartyKind::guess($name)],
        );
    }

    /**
     * Match a free-text name against the user's existing counterparties (name or alias). Never creates.
     * Used for names extracted heuristically from descriptions, so guesses don't pollute the list.
     */
    public function match(User $user, ?string $name): ?Counterparty
    {
        if ($name === null || ($normalized = self::normalize($name)) === '') {
            return null;
        }

        $exact = Counterparty::query()->where('user_id', $user->id)->where('normalized_name', $normalized)->first();
        if ($exact !== null) {
            return $exact;
        }

        return Counterparty::query()
            ->where('user_id', $user->id)
            ->whereNotNull('aliases')
            ->get()
            ->first(static fn (Counterparty $c): bool => in_array(
                $normalized,
                array_map(self::normalize(...), explode(',', (string) $c->aliases)),
                true,
            ));
    }
}
