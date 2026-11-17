<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Console;

use App\Modules\Telegram\Services\TelegramClient;
use Illuminate\Console\Command;

/**
 * Registers, removes or shows the bot webhook. Telegram only calls HTTPS URLs,
 * and every call carries TELEGRAM_WEBHOOK_SECRET so forged updates are rejected.
 */
final class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook {action=set : set|delete|info} {--url= : Public HTTPS base URL (default APP_URL)}';

    protected $description = 'Register, remove or inspect the Telegram bot webhook';

    public function handle(TelegramClient $telegram): int
    {
        if (! $telegram->configured()) {
            $this->error('TELEGRAM_BOT_TOKEN is not set.');

            return self::FAILURE;
        }

        return match ((string) $this->argument('action')) {
            'set' => $this->set($telegram),
            'delete' => $this->report($telegram->call('deleteWebhook', ['drop_pending_updates' => true]), 'Webhook removed.'),
            'info' => $this->showInfo($telegram),
            default => $this->invalid(),
        };
    }

    private function set(TelegramClient $telegram): int
    {
        $secret = (string) config('services.telegram.webhook_secret');
        if (strlen($secret) < 32 || preg_match('/^[A-Za-z0-9_-]+$/', $secret) !== 1) {
            $this->error('TELEGRAM_WEBHOOK_SECRET must be at least 32 characters of A-Z, a-z, 0-9, _ or -. Generate one with: php -r "echo bin2hex(random_bytes(32));"');

            return self::FAILURE;
        }

        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        if (! str_starts_with($base, 'https://')) {
            $this->error('Telegram only delivers to HTTPS URLs. Pass --url=https://your-domain');

            return self::FAILURE;
        }

        $result = $telegram->call('setWebhook', [
            'url' => $base.'/api/telegram/webhook',
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
            'max_connections' => 10,
        ]);

        $commands = $telegram->call('setMyCommands', ['commands' => [
            ['command' => 'saldo', 'description' => 'Saldo semua akun'],
            ['command' => 'ringkasan', 'description' => 'Ringkasan & budget bulan ini'],
            ['command' => 'terakhir', 'description' => '5 transaksi terakhir'],
            ['command' => 'batal', 'description' => 'Batalkan draf'],
            ['command' => 'bantuan', 'description' => 'Cara pakai'],
            ['command' => 'putuskan', 'description' => 'Putuskan Telegram dari akun'],
        ]]);

        if ($commands === null) {
            $this->warn('Webhook call done, but the command menu could not be set.');
        }

        return $this->report($result, 'Webhook set to '.$base.'/api/telegram/webhook');
    }

    private function showInfo(TelegramClient $telegram): int
    {
        $info = $telegram->call('getWebhookInfo');
        if ($info === null) {
            return $this->report(null, '');
        }

        $this->table(['Field', 'Value'], collect(['url', 'pending_update_count', 'last_error_date', 'last_error_message', 'max_connections'])
            ->map(static fn (string $k): array => [$k, (string) ($info[$k] ?? '—')])->all());

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>|null  $result */
    private function report(?array $result, string $success): int
    {
        if ($result === null) {
            $this->error('Telegram API call failed (see the application log).');

            return self::FAILURE;
        }

        $this->info($success);

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('Action must be set, delete or info.');

        return self::INVALID;
    }
}
