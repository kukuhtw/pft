<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Console;

use App\Modules\Telegram\Services\TelegramClient;
use App\Modules\Telegram\Services\TelegramSetupService;
use Illuminate\Console\Command;

/**
 * Registers, removes or shows the bot webhook. Telegram only calls HTTPS URLs,
 * and every call carries TELEGRAM_WEBHOOK_SECRET so forged updates are rejected.
 */
final class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook {action=set : set|delete|info} {--url= : Public HTTPS base URL (default APP_URL)}';

    protected $description = 'Register, remove or inspect the Telegram bot webhook';

    public function handle(TelegramClient $telegram, TelegramSetupService $setup): int
    {
        if (! $telegram->configured()) {
            $this->error('TELEGRAM_BOT_TOKEN is not set.');

            return self::FAILURE;
        }

        return match ((string) $this->argument('action')) {
            'set' => $this->set($setup),
            'delete' => $setup->deleteWebhook() ? $this->done('Webhook removed.') : $this->failed(),
            'info' => $this->showInfo($setup),
            default => $this->invalid(),
        };
    }

    private function set(TelegramSetupService $setup): int
    {
        $result = $setup->registerWebhook($this->option('url') ?: null);

        if (! $result['ok']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        if ($result['warning'] !== null) {
            $this->warn($result['warning']);
        }

        return $this->done($result['message']);
    }

    private function showInfo(TelegramSetupService $setup): int
    {
        $info = $setup->webhookInfo();
        if ($info === null) {
            return $this->failed();
        }

        $this->table(['Field', 'Value'], [
            ['url', $info['url'] ?: '—'],
            ['matches APP_URL', $info['matches'] ? 'yes' : 'no'],
            ['pending_update_count', (string) $info['pending']],
            ['last_error_message', $info['last_error'] ?? '—'],
        ]);

        return self::SUCCESS;
    }

    private function done(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    private function failed(): int
    {
        $this->error('Telegram API call failed (see the application log).');

        return self::FAILURE;
    }

    private function invalid(): int
    {
        $this->error('Action must be set, delete or info.');

        return self::INVALID;
    }
}
