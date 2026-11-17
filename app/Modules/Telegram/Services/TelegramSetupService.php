<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Services;

/**
 * Setup checks and webhook registration, shared by `php artisan telegram:webhook` and the
 * in-app setup guide. Never returns the bot token or the webhook secret, only whether they are valid.
 */
final readonly class TelegramSetupService
{
    public const string WEBHOOK_PATH = '/api/telegram/webhook';

    public const array COMMANDS = [
        ['command' => 'saldo', 'description' => 'Saldo semua akun'],
        ['command' => 'ringkasan', 'description' => 'Ringkasan & budget bulan ini'],
        ['command' => 'terakhir', 'description' => '5 transaksi terakhir'],
        ['command' => 'batal', 'description' => 'Batalkan draf'],
        ['command' => 'bantuan', 'description' => 'Cara pakai'],
        ['command' => 'putuskan', 'description' => 'Putuskan Telegram dari akun'],
    ];

    public function __construct(private TelegramClient $telegram) {}

    public static function secretValid(): bool
    {
        $secret = (string) config('services.telegram.webhook_secret');

        return strlen($secret) >= 32 && preg_match('/^[A-Za-z0-9_-]+$/', $secret) === 1;
    }

    public static function baseUrl(?string $override = null): string
    {
        return rtrim((string) ($override ?: config('app.url')), '/');
    }

    public static function webhookUrl(?string $override = null): string
    {
        return self::baseUrl($override).self::WEBHOOK_PATH;
    }

    /**
     * Local configuration checks (no network call).
     *
     * @return array<string, array{ok: bool, label: string, hint: string}>
     */
    public function localChecks(): array
    {
        $allowed = count(TelegramAccess::allowedUserIds());

        return [
            'token' => [
                'ok' => $this->telegram->configured(),
                'label' => 'TELEGRAM_BOT_TOKEN terisi',
                'hint' => 'Isi dengan token dari @BotFather (langkah 1 & 3).',
            ],
            'username' => [
                'ok' => (string) config('services.telegram.bot_username') !== '',
                'label' => 'TELEGRAM_BOT_USERNAME terisi',
                'hint' => 'Username bot tanpa @. Dipakai untuk tombol "Buka di Telegram".',
            ],
            'secret' => [
                'ok' => self::secretValid(),
                'label' => 'TELEGRAM_WEBHOOK_SECRET valid (≥ 32 karakter A–Z, a–z, 0–9, _ atau -)',
                'hint' => 'Pakai tombol "Buat secret acak" di bawah, lalu salin ke environment.',
            ],
            'allowlist' => [
                'ok' => $allowed > 0,
                'label' => 'TELEGRAM_ALLOWED_USER_IDS berisi ID ('.$allowed.' ID diizinkan)',
                'hint' => 'Kosong = bot menolak semua orang. Isi ID Telegram (angka), pisahkan dengan koma.',
            ],
            'https' => [
                'ok' => str_starts_with(self::baseUrl(), 'https://'),
                'label' => 'APP_URL memakai https://',
                'hint' => 'Telegram hanya mengirim ke alamat HTTPS. Isi APP_URL dengan domain publik Anda.',
            ],
        ];
    }

    /** Bot identity from Telegram (validates the token). Null when the token is missing or rejected. */
    public function botUsername(): ?string
    {
        $me = $this->telegram->call('getMe');

        return isset($me['username']) ? (string) $me['username'] : null;
    }

    /**
     * Current webhook state as Telegram sees it.
     *
     * @return array{url: string, matches: bool, pending: int, last_error: ?string, last_error_at: ?int}|null
     */
    public function webhookInfo(): ?array
    {
        $info = $this->telegram->call('getWebhookInfo');
        if ($info === null) {
            return null;
        }

        $url = (string) ($info['url'] ?? '');

        return [
            'url' => $url,
            'matches' => $url !== '' && $url === self::webhookUrl(),
            'pending' => (int) ($info['pending_update_count'] ?? 0),
            'last_error' => isset($info['last_error_message']) ? (string) $info['last_error_message'] : null,
            'last_error_at' => isset($info['last_error_date']) ? (int) $info['last_error_date'] : null,
        ];
    }

    /**
     * Register the webhook (with the secret header) and the command menu.
     *
     * @return array{ok: bool, message: string, warning: ?string}
     */
    public function registerWebhook(?string $baseUrl = null): array
    {
        if (! $this->telegram->configured()) {
            return ['ok' => false, 'message' => 'TELEGRAM_BOT_TOKEN belum diisi.', 'warning' => null];
        }
        if (! self::secretValid()) {
            return ['ok' => false, 'message' => 'TELEGRAM_WEBHOOK_SECRET harus minimal 32 karakter A–Z, a–z, 0–9, _ atau -.', 'warning' => null];
        }

        $base = self::baseUrl($baseUrl);
        if (! str_starts_with($base, 'https://')) {
            return ['ok' => false, 'message' => 'Telegram hanya mengirim ke alamat HTTPS. Periksa APP_URL.', 'warning' => null];
        }

        $result = $this->telegram->call('setWebhook', [
            'url' => $base.self::WEBHOOK_PATH,
            'secret_token' => (string) config('services.telegram.webhook_secret'),
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
            'max_connections' => 10,
        ]);
        if ($result === null) {
            return ['ok' => false, 'message' => 'Telegram menolak atau tidak bisa dihubungi. Periksa token dan log aplikasi.', 'warning' => null];
        }

        $warnings = [];
        if ($this->telegram->call('setMyCommands', ['commands' => self::COMMANDS]) === null) {
            $warnings[] = 'Menu perintah bot gagal diatur.';
        }
        if (TelegramAccess::allowedUserIds() === []) {
            $warnings[] = 'TELEGRAM_ALLOWED_USER_IDS masih kosong: bot mengabaikan semua pesan.';
        }

        return ['ok' => true, 'message' => 'Webhook terdaftar ke '.$base.self::WEBHOOK_PATH, 'warning' => $warnings === [] ? null : implode(' ', $warnings)];
    }

    public function deleteWebhook(): bool
    {
        return $this->telegram->call('deleteWebhook', ['drop_pending_updates' => true]) !== null;
    }
}
