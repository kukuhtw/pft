<?php

declare(strict_types=1);

namespace Tests\Feature\Telegram;

use App\Livewire\Profile;
use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Shared\Domain\Money;
use App\Modules\Telegram\Models\TelegramLink;
use App\Modules\Transactions\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

final class TelegramBotTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    private const string SECRET = 'test_secret_0123456789_abcdefghijklmnop';

    private int $updateId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'services.telegram.bot_token' => '123:test-token',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_username' => 'pft_test_bot',
            'services.telegram.allowed_user_ids' => '1001, 2002,3003,9999',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function say(int $chatId, string $text, string $chatType = 'private', ?int $fromId = null): void
    {
        $this->postJson('/api/telegram/webhook', [
            'update_id' => $this->updateId++,
            'message' => ['message_id' => 1, 'chat' => ['id' => $chatId, 'type' => $chatType], 'from' => ['id' => $fromId ?? $chatId], 'text' => $text],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function tap(int $chatId, string $data, ?int $fromId = null): void
    {
        $this->postJson('/api/telegram/webhook', [
            'update_id' => $this->updateId++,
            'callback_query' => ['id' => 'cb'.$this->updateId, 'from' => ['id' => $fromId ?? $chatId], 'data' => $data, 'message' => ['message_id' => 77, 'chat' => ['id' => $chatId, 'type' => 'private']]],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function replyCount(): int
    {
        return collect(Http::recorded())->filter(static fn (array $pair): bool => ! str_ends_with($pair[0]->url(), '/answerCallbackQuery'))->count();
    }

    /** @return array<string, mixed> Last text the bot sent or edited. */
    private function lastReply(): array
    {
        $calls = collect(Http::recorded())->map(static fn (array $pair): Request => $pair[0])
            ->filter(static fn (Request $r): bool => str_ends_with($r->url(), '/sendMessage') || str_ends_with($r->url(), '/editMessageText'));

        return $calls->last()?->data() ?? [];
    }

    /** @return list<string> */
    private function buttons(): array
    {
        return collect($this->lastReply()['reply_markup']['inline_keyboard'] ?? [])->flatten(1)->pluck('callback_data')->all();
    }

    private function linkedUser(int $chatId): User
    {
        $user = $this->provisionedUser(['name' => 'Ani']);
        $this->actingAs($user);
        $code = Livewire::test(Profile::class)->call('createTelegramCode')->get('telegramCode');
        auth()->logout();

        $this->say($chatId, '/start '.$code);

        return $user;
    }

    // ── tests ────────────────────────────────────────────────────────────────

    public function test_webhook_rejects_requests_without_the_secret_and_is_off_when_unconfigured(): void
    {
        $update = ['update_id' => 1, 'message' => ['chat' => ['id' => 5, 'type' => 'private'], 'text' => '/saldo']];

        $this->postJson('/api/telegram/webhook', $update)->assertForbidden();
        $this->postJson('/api/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();

        config(['services.telegram.webhook_secret' => '']);
        $this->postJson('/api/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => ''])->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_only_telegram_users_listed_in_the_env_allowlist_are_served(): void
    {
        $user = $this->provisionedUser();
        $this->actingAs($user);
        $code = Livewire::test(Profile::class)->call('createTelegramCode')->get('telegramCode');

        // Not on the list: even a valid code links nothing, and the bot does not answer at all.
        $this->say(5555, '/start '.$code);
        $this->say(5555, '/saldo');
        $this->say(5555, 'makan siang 45rb');
        $this->assertSame(0, $this->replyCount(), 'Unlisted senders get no reply.');
        $this->assertFalse(TelegramLink::query()->exists());

        // The code was not consumed by the rejected sender, so an allowed user can still use it.
        $this->say(1001, '/start '.$code);
        $this->assertTrue(TelegramLink::query()->where('chat_id', 1001)->exists());

        // A linked chat whose sender is removed from the list is cut off immediately.
        $bca = $this->openAccount($user, 'BCA', 'BANK', opening: 1_000_000);
        $this->say(1001, 'makan siang 45rb');
        config(['services.telegram.allowed_user_ids' => '2002']);
        $before = $this->replyCount();
        $this->tap(1001, 'ok');
        $this->say(1001, '/saldo');
        $this->assertSame($before, $this->replyCount());
        $this->assertSame(0, Transaction::query()->where('channel', 'TELEGRAM')->count());
        $this->assertSame('1000000.00', $this->balanceOf($bca));

        // Forged sender id on a callback in an allowed chat is rejected too.
        config(['services.telegram.allowed_user_ids' => '1001']);
        $this->tap(1001, 'ok', fromId: 6666);
        $this->assertSame(0, Transaction::query()->where('channel', 'TELEGRAM')->count());
    }

    public function test_empty_allowlist_serves_nobody_and_profile_shows_the_bot_as_off(): void
    {
        config(['services.telegram.allowed_user_ids' => '']);
        $this->say(1001, '/start ABCDEFGH');
        $this->say(1001, '/bantuan');
        $this->assertSame(0, $this->replyCount());

        $this->actingAs($this->provisionedUser());
        Livewire::test(Profile::class)->assertSee('Bot Telegram belum diaktifkan');
    }

    public function test_linking_uses_a_single_use_code_from_the_profile_page(): void
    {
        $user = $this->provisionedUser();
        $this->actingAs($user);

        $component = Livewire::test(Profile::class)->call('createTelegramCode')->assertHasNoErrors();
        $code = $component->get('telegramCode');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $code);
        $component->assertSee('https://t.me/pft_test_bot?start='.$code, false);

        $this->say(1001, '/start '.strtolower($code));
        $this->assertSame(1001, TelegramLink::query()->where('user_id', $user->id)->value('chat_id'));
        $this->assertStringContainsString('Terhubung', $this->lastReply()['text']);

        // The code is single use: another chat cannot reuse it.
        $this->say(2002, '/start '.$code);
        $this->assertStringContainsString('tidak valid', $this->lastReply()['text']);
        $this->assertFalse(TelegramLink::query()->where('chat_id', 2002)->exists());

        // A newer code invalidates the previous unused one.
        $first = Livewire::test(Profile::class)->call('createTelegramCode')->get('telegramCode');
        Livewire::test(Profile::class)->call('createTelegramCode');
        $this->say(3003, '/start '.$first);
        $this->assertFalse(TelegramLink::query()->where('chat_id', 3003)->exists());

        $this->assertTrue(AuditLog::query()->where('event', 'telegram.linked')->exists());
    }

    public function test_unlinked_chats_and_group_chats_get_no_financial_data(): void
    {
        $user = $this->linkedUser(1001);
        $this->openAccount($user, 'BCA Gaji', 'BANK', opening: 5_000_000);

        $this->say(9999, '/saldo');
        $this->assertStringContainsString('belum terhubung', $this->lastReply()['text']);
        $this->assertStringNotContainsString('BCA', $this->lastReply()['text']);

        $this->say(1001, '/saldo', chatType: 'group');
        $this->assertStringContainsString('hanya melayani chat pribadi', $this->lastReply()['text']);

        $this->say(1001, '/saldo');
        $this->assertStringContainsString('BCA Gaji', $this->lastReply()['text']);
        $this->assertStringContainsString('5.000.000', $this->lastReply()['text']);
    }

    public function test_expense_is_posted_only_after_choosing_an_account_and_confirming(): void
    {
        $user = $this->linkedUser(1001);
        $bca = $this->openAccount($user, 'BCA', 'BANK', opening: 1_000_000);
        $this->openAccount($user, 'Dompet', 'CASH', opening: 200_000);

        $this->say(1001, 'makan siang 45rb');
        $this->assertContains('s:'.$bca->id, $this->buttons(), 'Two asset accounts: the bot asks which one.');
        $this->assertSame(0, Transaction::query()->where('user_id', $user->id)->where('channel', 'TELEGRAM')->count());

        // A forged button (another user's account) is ignored.
        $stranger = $this->provisionedUser();
        $foreign = $this->openAccount($stranger, 'Foreign', 'BANK');
        $this->tap(1001, 's:'.$foreign->id);

        $this->tap(1001, 's:'.$bca->id);
        $this->assertStringContainsString('Periksa sebelum disimpan', $this->lastReply()['text']);
        $this->assertSame(['ok', 'no', 'chg', 'acc'], $this->buttons());

        $this->tap(1001, 'ok');
        $tx = Transaction::query()->where('user_id', $user->id)->where('channel', 'TELEGRAM')->sole();
        $this->assertSame('EXPENSE', $tx->intent?->value);
        $this->assertSame($bca->id, $tx->source_account_id);
        $this->assertSame('FOOD', $tx->category?->code, '"makan" lands in the food category, so the food budget counts it.');
        $this->assertSame('955000.00', $this->balanceOf($bca));
        $this->assertStringContainsString('Tersimpan', $this->lastReply()['text']);

        // Double tap: nothing posted twice.
        $this->tap(1001, 'ok');
        $this->assertSame(1, Transaction::query()->where('user_id', $user->id)->where('channel', 'TELEGRAM')->count());
        $this->assertLedgerIntact($user);
    }

    public function test_expense_source_named_in_text_and_change_account_button(): void
    {
        $user = $this->linkedUser(1001);
        $bca = $this->openAccount($user, 'BCA Gaji', 'BANK', institution: 'BCA', opening: 1_000_000);
        $gopay = $this->openAccount($user, 'GoPay', 'EWALLET', opening: 300_000);
        $wallet = $this->openAccount($user, 'Dompet', 'CASH', opening: 100_000);
        $receivable = $this->openAccount($user, 'Piutang Andi', 'RECEIVABLE');

        // Nothing named: the bot asks, and a receivable is never offered for spending.
        $this->say(1001, 'parkir 5rb');
        $this->assertStringContainsString('Dibayar dari pos mana', $this->lastReply()['text']);
        $this->assertNotContains('s:'.$receivable->id, $this->buttons());
        $this->assertCount(4, $this->buttons(), '3 money accounts + Batal.');
        $this->tap(1001, 'no');

        $this->say(1001, 'makan siang 45rb pakai gopay');
        $this->assertStringContainsString('Dari: GoPay', $this->lastReply()['text']);
        $this->tap(1001, 'acc');
        $this->assertContains('s:'.$wallet->id, $this->buttons());
        $this->tap(1001, 's:'.$wallet->id);
        $this->tap(1001, 'ok');
        $this->assertSame('55000.00', $this->balanceOf($wallet));
        $this->assertSame('300000.00', $this->balanceOf($gopay));

        $this->say(1001, 'jajan 20rb tunai');
        $this->assertStringContainsString('Dari: Dompet', $this->lastReply()['text']);
        $this->tap(1001, 'no');

        // Transfer: words before "ke" name the source, after it the destination (bank code matches too).
        $this->say(1001, 'transfer ke gopay dari bca 100rb');
        $this->assertStringContainsString('Dari: BCA Gaji', $this->lastReply()['text']);
        $this->assertStringContainsString('Ke: GoPay', $this->lastReply()['text']);
        $this->tap(1001, 'ok');
        $this->assertSame('900000.00', $this->balanceOf($bca));
        $this->assertLedgerIntact($user);
    }

    public function test_loan_from_a_party_then_repayment_to_the_same_party(): void
    {
        $user = $this->linkedUser(1001);
        $bca = $this->openAccount($user, 'BCA', 'BANK', opening: 0);

        $this->say(1001, 'pinjam dari Pak Budi 2jt');
        $this->assertContains('d:'.$bca->id, $this->buttons(), 'Even with one account the bot asks where the money goes.');
        $this->tap(1001, 'd:'.$bca->id);
        $this->assertStringContainsString('Pinjaman diterima', $this->lastReply()['text']);
        $this->tap(1001, 'ok');

        $this->say(1001, 'bayar hutang ke Budi 500rb pakai BCA');
        $this->assertStringContainsString('Pembayaran hutang', $this->lastReply()['text']);
        $this->assertStringContainsString('Dari: BCA', $this->lastReply()['text'], 'Named in the text: no question needed.');
        $this->tap(1001, 'ok');

        $intents = Transaction::query()->where('user_id', $user->id)->where('channel', 'TELEGRAM')->orderBy('id')->pluck('intent')->map->value->all();
        $this->assertSame(['LOAN_RECEIVED', 'DEBT_REPAYMENT'], $intents);
        $this->assertSame('1500000.00', $this->balanceOf($bca));
        $this->assertLedgerIntact($user);
    }

    public function test_loan_purpose_is_confirmed_and_listed_by_the_hutang_command(): void
    {
        $user = $this->linkedUser(1001);
        $bca = $this->openAccount($user, 'BCA', 'BANK', opening: 0);

        $this->say(1001, '/hutang');
        $this->assertStringContainsString('Tidak ada hutang', $this->lastReply()['text']);

        $this->say(1001, 'pinjam dari Pak Budi 2jt untuk bayar sekolah');
        $this->tap(1001, 'd:'.$bca->id);
        $this->assertStringContainsString('Keperluan: bayar sekolah', $this->lastReply()['text']);
        $this->tap(1001, 'ok');

        $this->assertSame('bayar sekolah', Transaction::query()->where('user_id', $user->id)->where('intent', 'LOAN_RECEIVED')->sole()->purpose);

        $this->say(1001, '/hutang');
        $text = $this->lastReply()['text'];
        $this->assertStringContainsString('Pak Budi', $text);
        $this->assertStringContainsString('untuk bayar sekolah', $text);
        $this->assertStringContainsString('Rp2.000.000', $text);
    }

    public function test_bot_asks_for_a_missing_amount_and_intent_can_be_changed(): void
    {
        $user = $this->linkedUser(1001);
        $bca = $this->openAccount($user, 'BCA', 'BANK', opening: 1_000_000);

        $this->say(1001, 'bayar listrik');
        $this->assertStringContainsString('nominalnya berapa', $this->lastReply()['text']);

        $this->say(1001, '350rb');
        $this->assertStringContainsString('Dibayar dari pos mana', $this->lastReply()['text']);
        $this->tap(1001, 's:'.$bca->id);
        $this->assertStringContainsString('Rp', $this->lastReply()['text']);
        $this->assertContains('chg', $this->buttons());

        $this->tap(1001, 'chg');
        $this->assertContains('i:OTHER_INCOME', $this->buttons());
        $this->tap(1001, 'i:EXPENSE');
        $this->tap(1001, 's:'.$bca->id);
        $this->tap(1001, 'ok');

        $tx = Transaction::query()->where('user_id', $user->id)->where('channel', 'TELEGRAM')->sole();
        $this->assertSame('EXPENSE', $tx->intent?->value);
        $this->assertSame('350000.00', Money::fromDatabase($tx->getRawOriginal('amount'))->amount);
    }

    public function test_unlinking_from_telegram_and_on_password_change(): void
    {
        $user = $this->linkedUser(1001);

        $this->say(1001, '/putuskan');
        $this->assertFalse(TelegramLink::query()->where('user_id', $user->id)->exists());

        $user->forceFill(['password' => 'Rahasia-Kuat-123'])->save();
        $this->say(1001, '/start '.Livewire::actingAs($user)->test(Profile::class)->call('createTelegramCode')->get('telegramCode'));
        $this->assertTrue(TelegramLink::query()->where('user_id', $user->id)->exists());

        Livewire::actingAs($user)->test(Profile::class)
            ->set('current_password', 'Rahasia-Kuat-123')->set('password', 'Baru-Lebih-Kuat-456')->set('password_confirmation', 'Baru-Lebih-Kuat-456')
            ->call('changePassword')->assertHasNoErrors();

        $this->assertFalse(TelegramLink::query()->where('user_id', $user->id)->exists(), 'A password change also cuts the Telegram link.');
    }

    public function test_personal_data_export_lists_the_telegram_link(): void
    {
        $user = $this->linkedUser(1001);
        $this->actingAs($user);

        $data = $this->get('/export/my-data.json')->assertOk()->json();
        $this->assertSame(1001, $data['telegram']['chat_id']);
    }
}
