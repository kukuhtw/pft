<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Services;

/**
 * Which Telegram accounts may talk to the bot (ISO/IEC 27001 A.5.15 / A.8.3).
 * Configured with TELEGRAM_ALLOWED_USER_IDS: comma-separated numeric Telegram user ids.
 * Fail closed: when the list is empty the bot serves nobody.
 */
final class TelegramAccess
{
    /** @return list<int> */
    public static function allowedUserIds(): array
    {
        $raw = config('services.telegram.allowed_user_ids', []);
        $items = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_unique(array_map(
            static fn (string $id): int => (int) $id,
            array_filter(array_map(static fn ($id): string => trim((string) $id), $items), static fn (string $id): bool => ctype_digit($id) && $id !== '0'),
        )));
    }

    public static function enabled(): bool
    {
        return (string) config('services.telegram.bot_token') !== ''
            && (string) config('services.telegram.webhook_secret') !== ''
            && self::allowedUserIds() !== [];
    }
}
