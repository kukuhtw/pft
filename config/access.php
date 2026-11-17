<?php

declare(strict_types=1);

/*
| Who may sign in to the dashboard and API (ISO/IEC 27001 A.5.15 / A.5.16 / A.5.17).
| Passwords are never checked against .env at login: `php artisan dashboard:user` copies the
| DASHBOARD_ADMIN_* account into the database once (bcrypt), and login uses the database only.
*/

return [
    // false = /register (web) and POST /api/v1/auth/register are closed.
    'registration_enabled' => filter_var(env('AUTH_REGISTRATION_ENABLED', true), FILTER_VALIDATE_BOOL),

    // Comma-separated emails allowed to sign in / register. Empty = every registered user.
    'allowed_emails' => (string) env('AUTH_ALLOWED_EMAILS', ''),

    // Dashboard account managed from .env by `php artisan dashboard:user`.
    'admin' => [
        'name' => (string) env('DASHBOARD_ADMIN_NAME', 'Admin'),
        'email' => (string) env('DASHBOARD_ADMIN_EMAIL', ''),
        // Prefer a bcrypt hash (php artisan dashboard:user --hash). A plain password also works
        // but must meet the password policy and should be removed from .env after syncing.
        'password' => (string) env('DASHBOARD_ADMIN_PASSWORD', ''),
    ],
];
