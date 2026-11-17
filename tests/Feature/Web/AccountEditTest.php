<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Livewire\Accounts;
use App\Modules\Accounts\Models\AccountType;
use App\Modules\Accounts\Services\FinancialAccountService;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Interpretation\Enums\TransactionIntent;
use App\Modules\Interpretation\Services\IntentPostingService;
use App\Modules\Ledger\Enums\JournalEntryType;
use App\Modules\Shared\Domain\Money;
use App\Modules\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

final class AccountEditTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_rename_updates_account_and_ledger_name_and_keeps_balance(): void
    {
        $user = $this->provisionedUser();
        $account = $this->openAccount($user, 'BCA', opening: 500000);
        $this->openAccount($user, 'Mandiri');
        $this->actingAs($user);

        Livewire::test(Accounts::class)->call('edit', $account->id)
            ->set('editName', 'Mandiri')->call('saveEdit')->assertHasErrors('editName'); // unique per user

        Livewire::test(Accounts::class)->call('edit', $account->id)
            ->set('editName', 'BCA Gaji')->set('editLast4', '1234')->call('saveEdit')->assertHasNoErrors();

        $account->refresh()->load('ledgerAccount');
        $this->assertSame('BCA Gaji', $account->name);
        $this->assertSame('1234', $account->account_number_last4);
        $this->assertSame('BCA Gaji', $account->ledgerAccount->name);
        $this->assertSame('500000.00', $this->balanceOf($account));
        $this->assertTrue(AuditLog::query()->where('event', 'account.updated')->exists());
    }

    public function test_edit_bank_and_debt_party(): void
    {
        $user = $this->provisionedUser();
        $bank = $this->openAccount($user, 'Tabungan', institution: 'BCA', opening: 100000);
        $loan = $this->openAccount($user, 'Pinjaman', type: 'LOAN', opening: 2000000);
        $this->actingAs($user);

        Livewire::test(Accounts::class)->call('edit', $bank->id)
            ->assertSet('editInstitutionCode', 'BCA')
            ->set('editInstitutionCode', 'BNI')->set('editCounterparty', 'Pak Budi') // ignored: not a debt
            ->call('saveEdit')->assertHasNoErrors();
        $bank->refresh()->load('institution');
        $this->assertSame('BNI', $bank->institution?->code);
        $this->assertNull($bank->counterparty_id);

        Livewire::test(Accounts::class)->call('edit', $bank->id)
            ->set('editInstitutionCode', 'NOPE')->call('saveEdit')->assertHasErrors('editInstitutionCode');

        Livewire::test(Accounts::class)->call('edit', $loan->id)
            ->set('editCounterparty', 'Pak Budi')->call('saveEdit')->assertHasNoErrors();
        $this->assertSame('Pak Budi', $loan->fresh()->counterparty?->name);

        Livewire::test(Accounts::class)->call('edit', $loan->id)
            ->assertSet('editCounterparty', 'Pak Budi')
            ->set('editCounterparty', '')->set('editInstitutionCode', '')->call('saveEdit')->assertHasNoErrors();
        $this->assertNull($loan->fresh()->counterparty_id);
        $this->assertSame('2000000.00', $this->balanceOf($loan));
        $this->assertLedgerIntact($user);
    }

    public function test_correct_opening_balance_up_and_down_for_an_asset(): void
    {
        $user = $this->provisionedUser();
        $account = $this->openAccount($user, 'BCA', opening: 1000000);
        $this->expense($user, $account, 200000);
        $service = app(FinancialAccountService::class);

        $up = $service->correctOpeningBalance($user, $account, Money::of(1500000));
        $this->assertSame('1300000.00', $this->balanceOf($account));
        $this->assertSame('1500000.00', (string) $account->fresh()->opening_balance);
        $this->assertSame(TransactionIntent::OPENING_BALANCE, $up->intent);
        $this->assertSame(JournalEntryType::OPENING_BALANCE, $up->journalEntries()->first()->entry_type);
        $this->assertSame($account->id, $up->destination_account_id);

        $down = $service->correctOpeningBalance($user, $account->fresh(), Money::of(800000));
        $this->assertSame('600000.00', $this->balanceOf($account));
        $this->assertSame($account->id, $down->source_account_id);

        $this->assertNull($service->correctOpeningBalance($user, $account->fresh(), Money::of(800000)), 'No change, nothing posted.');

        // Not income, not spending.
        $summary = $this->summary($user);
        $this->assertSame('0.00', $summary->totalIncome->amount);
        $this->assertSame('200000.00', $summary->totalExpenses->amount);
        $this->assertLedgerIntact($user);
    }

    public function test_correct_opening_balance_of_a_debt(): void
    {
        $user = $this->provisionedUser();
        $loan = $this->openAccount($user, 'Pinjaman Budi', type: 'LOAN', opening: 3000000);

        app(FinancialAccountService::class)->correctOpeningBalance($user, $loan, Money::of(2500000));
        $this->assertSame('2500000.00', $this->balanceOf($loan));

        app(FinancialAccountService::class)->correctOpeningBalance($user, $loan->fresh(), Money::of(4000000));
        $this->assertSame('4000000.00', $this->balanceOf($loan));
        $this->assertLedgerIntact($user);
    }

    public function test_adjust_balance_today_posts_the_difference_as_balance_adjustment(): void
    {
        $user = $this->provisionedUser();
        $wallet = $this->openAccount($user, 'Dompet', type: 'CASH', opening: 300000);
        $service = app(FinancialAccountService::class);

        $t = $service->adjustBalanceToday($user, $wallet, Money::of(275000));
        $this->assertSame('275000.00', $this->balanceOf($wallet));
        $this->assertSame(TransactionIntent::BALANCE_ADJUSTMENT, $t->intent);
        $this->assertSame(JournalEntryType::ADJUSTMENT, $t->journalEntries()->first()->entry_type);
        $this->assertSame(CarbonImmutable::now((string) config('finance.display_timezone'))->toDateString(), $t->transaction_date->toDateString());
        $this->assertSame('300000.00', (string) $wallet->fresh()->opening_balance, 'Opening balance is left alone.');

        $service->adjustBalanceToday($user, $wallet->fresh(), Money::zero());
        $this->assertSame('0.00', $this->balanceOf($wallet));

        $this->assertNull($service->adjustBalanceToday($user, $wallet->fresh(), Money::zero()));
        $this->assertSame('0.00', $this->summary($user)->totalExpenses->amount);
        $this->assertLedgerIntact($user);
    }

    public function test_balance_edit_via_the_page_and_validation(): void
    {
        $user = $this->provisionedUser();
        $account = $this->openAccount($user, 'BCA', opening: 100000);
        $this->actingAs($user);

        Livewire::test(Accounts::class)->call('edit', $account->id)
            ->set('balanceMode', 'opening')->set('targetBalance', 'abc')->call('saveEdit')->assertHasErrors('targetBalance');

        Livewire::test(Accounts::class)->call('edit', $account->id)
            ->set('balanceMode', 'opening')->set('targetBalance', '1,5jt')->call('saveEdit')->assertHasNoErrors()
            ->assertSee('saldo awal dikoreksi');
        $this->assertSame('1500000.00', $this->balanceOf($account));

        Livewire::test(Accounts::class)->call('edit', $account->id)
            ->set('balanceMode', 'today')->set('targetBalance', '0')->call('saveEdit')->assertHasNoErrors();
        $this->assertSame('0.00', $this->balanceOf($account));
        $this->assertSame(2, Transaction::query()->where('user_id', $user->id)->where('type', 'ADJUSTMENT')->where('description', 'like', '%→%')->count());
    }

    public function test_cannot_edit_another_users_account(): void
    {
        $owner = $this->provisionedUser();
        $account = $this->openAccount($owner, 'BCA', opening: 100000);
        $intruder = $this->provisionedUser();
        $this->actingAs($intruder);

        Livewire::test(Accounts::class)->call('edit', $account->id)->assertStatus(404);
        $this->assertSame('BCA', $account->fresh()->name);
    }

    public function test_balance_corrections_are_not_selectable_in_quick_entry_and_cash_is_labelled(): void
    {
        $this->assertNotContains(TransactionIntent::BALANCE_ADJUSTMENT, IntentPostingService::selectableIntents());
        $this->assertNotContains(TransactionIntent::OPENING_BALANCE, IntentPostingService::selectableIntents());
        $this->assertSame('Uang Tunai / Kas (dompet fisik)', AccountType::query()->find('CASH')?->name);
    }
}
