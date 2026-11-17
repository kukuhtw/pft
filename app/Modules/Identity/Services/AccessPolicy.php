<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

/** Reads the .env-driven sign-in rules from config/access.php. */
final class AccessPolicy
{
    public static function registrationEnabled(): bool
    {
        return (bool) config('access.registration_enabled', true);
    }

    /** @return list<string> lower-cased; empty = no restriction */
    public static function allowedEmails(): array
    {
        $emails = array_map(static fn (string $e): string => mb_strtolower(trim($e)), explode(',', (string) config('access.allowed_emails', '')));

        return array_values(array_unique(array_filter($emails, static fn (string $e): bool => $e !== '')));
    }

    /**
     * The dashboard admin is the DASHBOARD_ADMIN_EMAIL account. Fail closed: no email configured = no admin,
     * so admin-only actions (e.g. registering the Telegram webhook from the web) stay on the CLI.
     */
    public static function isAdmin(?string $email): bool
    {
        $admin = mb_strtolower(trim((string) config('access.admin.email', '')));

        return $admin !== '' && $admin === mb_strtolower(trim((string) $email));
    }

    public static function emailAllowed(?string $email): bool
    {
        $allowed = self::allowedEmails();

        return $allowed === [] || in_array(mb_strtolower(trim((string) $email)), $allowed, true);
    }
}
