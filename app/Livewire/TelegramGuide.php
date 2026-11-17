<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Services\AccessPolicy;
use App\Modules\Telegram\Services\TelegramAccess;
use App\Modules\Telegram\Services\TelegramLinkService;
use App\Modules\Telegram\Services\TelegramSetupService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * In-app Telegram bot setup guide with live checks. Secrets are never shown: only whether they are valid.
 * Calls to the Telegram API (check / register webhook) are admin-only, throttled and audited.
 */
#[Title('Panduan Telegram')]
final class TelegramGuide extends Component
{
    /** Result of "Cek koneksi Telegram" (admin). */
    #[Locked]
    public ?array $remote = null;

    #[Locked]
    public ?string $flash = null;

    #[Locked]
    public ?string $warning = null;

    /** Freshly generated webhook secret: shown once, never stored or logged. */
    #[Locked]
    public ?string $generatedSecret = null;

    public function checkTelegram(TelegramSetupService $setup): void
    {
        if (! $this->authorizeAdminAction('check', 10)) {
            return;
        }

        $info = $setup->webhookInfo();
        $tz = (string) config('finance.display_timezone');

        $this->remote = [
            'bot' => $setup->botUsername(),
            'reachable' => $info !== null,
            'url' => $info['url'] ?? '',
            'matches' => $info['matches'] ?? false,
            'pending' => $info['pending'] ?? 0,
            'last_error' => $info['last_error'] ?? null,
            'last_error_at' => isset($info['last_error_at'])
                ? CarbonImmutable::createFromTimestamp($info['last_error_at'], $tz)->translatedFormat('d M Y H:i')
                : null,
        ];
    }

    public function registerWebhook(TelegramSetupService $setup, AuditLogger $audit): void
    {
        if (! $this->authorizeAdminAction('register', 3)) {
            return;
        }

        $result = $setup->registerWebhook();
        $this->flash = $result['ok'] ? $result['message'] : null;
        $this->warning = $result['warning'];
        if (! $result['ok']) {
            $this->addError('telegram', $result['message']);

            return;
        }

        $audit->record('telegram.webhook_registered', $this->user(), null, ['url' => TelegramSetupService::webhookUrl()]);
        $this->checkTelegram($setup);
    }

    public function generateSecret(): void
    {
        $this->generatedSecret = bin2hex(random_bytes(32));
    }

    public function render(TelegramLinkService $links, TelegramSetupService $setup): View
    {
        $checks = $setup->localChecks();

        return view('livewire.telegram-guide', [
            'checks' => $checks,
            'botEnabled' => TelegramAccess::enabled(),
            'isAdmin' => AccessPolicy::isAdmin($this->user()->email),
            'webhookUrl' => TelegramSetupService::webhookUrl(),
            'appUrl' => TelegramSetupService::baseUrl(),
            'botUsername' => (string) config('services.telegram.bot_username'),
            'linked' => $links->forUser($this->user()) !== null,
            'commands' => TelegramSetupService::COMMANDS,
            'linkTtl' => (int) config('services.telegram.link_ttl_minutes', 10),
        ]);
    }

    private function authorizeAdminAction(string $action, int $perTenMinutes): bool
    {
        $this->resetErrorBag();
        if (! AccessPolicy::isAdmin($this->user()->email)) {
            $this->addError('telegram', 'Hanya admin dashboard (DASHBOARD_ADMIN_EMAIL) yang bisa melakukan ini.');

            return false;
        }

        $key = 'telegram-setup:'.$action.':'.$this->user()->id;
        if (RateLimiter::tooManyAttempts($key, $perTenMinutes)) {
            $this->addError('telegram', 'Terlalu sering. Coba lagi dalam beberapa menit.');

            return false;
        }
        RateLimiter::hit($key, 600);

        return true;
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
