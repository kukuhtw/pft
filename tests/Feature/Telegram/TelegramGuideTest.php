<?php

declare(strict_types=1);

namespace Tests\Feature\Telegram;

use App\Livewire\TelegramGuide;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

final class TelegramGuideTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    private const string TOKEN = '123456:SECRET-TOKEN-VALUE';

    private const string SECRET = 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'app.url' => 'https://keuangan.example.test',
            'services.telegram.bot_token' => self::TOKEN,
            'services.telegram.bot_username' => 'contoh_bot',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.allowed_user_ids' => '1001',
            'access.admin.email' => 'admin@example.test',
        ]);
    }

    public function test_page_shows_status_without_leaking_secrets(): void
    {
        $this->actingAs($this->provisionedUser());

        $this->get('/panduan/telegram')->assertOk()
            ->assertSee('Panduan setup bot Telegram')
            ->assertSee('https://keuangan.example.test/api/telegram/webhook')
            ->assertSee('1 ID diizinkan')
            ->assertDontSee(self::TOKEN)
            ->assertDontSee(self::SECRET);
    }

    public function test_missing_config_is_reported(): void
    {
        config(['services.telegram.webhook_secret' => 'short', 'services.telegram.allowed_user_ids' => '', 'app.url' => 'http://localhost']);
        $this->actingAs($this->provisionedUser());

        Livewire::test(TelegramGuide::class)
            ->assertSee('Bot belum aktif')
            ->assertSee('Kosong = bot menolak semua orang')
            ->assertSee('Telegram hanya mengirim ke alamat HTTPS');
    }

    public function test_only_the_admin_can_call_telegram(): void
    {
        Http::fake();
        $this->actingAs($this->provisionedUser(['email' => 'anggota@example.test']));

        Livewire::test(TelegramGuide::class)
            ->assertDontSee('wire:click="registerWebhook"', false)->assertSee('hanya tersedia untuk admin dashboard')
            ->call('registerWebhook')->assertHasErrors('telegram')
            ->call('checkTelegram')->assertHasErrors('telegram');

        Http::assertNothingSent();
    }

    public function test_admin_registers_webhook_and_checks_status(): void
    {
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'contoh_bot']]),
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => 'https://keuangan.example.test/api/telegram/webhook', 'pending_update_count' => 0]]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);
        $this->actingAs($this->provisionedUser(['email' => 'Admin@Example.test']));

        Livewire::test(TelegramGuide::class)
            ->assertSee('wire:click="registerWebhook"', false)
            ->call('registerWebhook')->assertHasNoErrors()
            ->assertSee('Webhook terdaftar ke https://keuangan.example.test/api/telegram/webhook')
            ->assertSee('Valid, bot @contoh_bot')
            ->assertDontSee(self::SECRET);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/setWebhook')
            && $request['secret_token'] === self::SECRET
            && $request['url'] === 'https://keuangan.example.test/api/telegram/webhook');
        $this->assertTrue(AuditLog::query()->where('event', 'telegram.webhook_registered')->exists());
    }

    public function test_generated_secret_is_valid_and_not_persisted(): void
    {
        $this->actingAs($this->provisionedUser());

        $secret = Livewire::test(TelegramGuide::class)->call('generateSecret')->get('generatedSecret');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $secret);
        $this->assertFalse(AuditLog::query()->where('properties', 'like', '%'.$secret.'%')->exists());
    }

    public function test_guests_cannot_open_the_guide(): void
    {
        $this->get('/panduan/telegram')->assertRedirect('/login');
    }
}
