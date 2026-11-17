<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Livewire\Accounts;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Dashboard;
use App\Livewire\QuickEntry;
use App\Livewire\Transactions;
use App\Models\User;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Transactions\Enums\TransactionStatus;
use App\Modules\Transactions\Enums\TransactionType;
use App\Modules\Transactions\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

final class WebDashboardTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite(); // no compiled assets needed to render pages in tests
    }

    public function test_guests_are_sent_to_login_and_login_page_renders(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Masuk');
    }

    public function test_landing_page_explains_the_money_rules_and_self_hosting(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Transfer antar rekening sendiri bukan pengeluaran')
            ->assertSee('Pinjaman yang Anda terima bukan pemasukan')
            ->assertSee('Meminjamkan uang ke orang lain bukan pengeluaran')
            ->assertSee('Rp30 ribu per bulan', false)
            ->assertSee(route('register'));

        config(['access.registration_enabled' => false]);
        $this->get('/')->assertOk()->assertDontSee('href="'.route('register').'"', false);

        $this->actingAs($this->provisionedUser());
        $this->get('/')->assertOk()->assertSee('Buka dashboard');
    }

    public function test_login_success_and_generic_failure_message(): void
    {
        $user = $this->provisionedUser(['email' => 'ani@example.test', 'password' => 'Rahasia-Kuat-123']);

        $wrong = Livewire::test(Login::class)->set('email', 'ani@example.test')->set('password', 'salah')->call('login')->assertHasErrors('email');
        $unknown = Livewire::test(Login::class)->set('email', 'tidak-ada@example.test')->set('password', 'salah')->call('login')->assertHasErrors('email');
        $this->assertSame($wrong->errors()->first('email'), $unknown->errors()->first('email'), 'No account enumeration.');
        $this->assertGuest();

        Livewire::test(Login::class)->set('email', 'ANI@example.test ')->set('password', 'Rahasia-Kuat-123')->call('login')
            ->assertHasNoErrors()->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_is_throttled(): void
    {
        $this->provisionedUser(['email' => 'ani@example.test', 'password' => 'Rahasia-Kuat-123']);

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)->set('email', 'ani@example.test')->set('password', 'salah')->call('login');
        }

        // Even the right password is refused while throttled.
        Livewire::test(Login::class)->set('email', 'ani@example.test')->set('password', 'Rahasia-Kuat-123')->call('login')->assertHasErrors('email');
        $this->assertGuest();
    }

    public function test_register_provisions_the_user_and_logs_in(): void
    {
        Livewire::test(Register::class)
            ->set('name', 'Budi')->set('email', 'budi@example.test')
            ->set('password', 'Rahasia-Kuat-123')->set('password_confirmation', 'Rahasia-Kuat-123')
            ->call('register')
            ->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'budi@example.test')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($this->category($user, 'SALARY'));
    }

    public function test_dashboard_shows_income_by_meaning_and_debts_per_party_for_the_owner_only(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');
        $this->income($user, $bca, 10_000_000, 'SALARY', description: 'Gaji November');
        $this->actingAs($user);
        Livewire::test(QuickEntry::class)
            ->set('text', 'pinjam dari Pak Budi 2jt')->call('interpret')
            ->set('destinationAccountId', $bca->id)->call('save')->assertHasNoErrors();

        $other = $this->provisionedUser();
        $otherCash = $this->openAccount($other, 'Rahasia Orang Lain', 'CASH');
        $this->income($other, $otherCash, 777_000, 'GIFT');

        $this->get('/dashboard')->assertOk()
            ->assertSee('Pemasukan gaji')
            ->assertSee('Rp10.000.000')
            ->assertSee('Pinjaman diterima')
            ->assertSee('bukan pendapatan')
            ->assertSee('Pak Budi')
            ->assertSee('Rp2.000.000')
            ->assertDontSee('Rahasia Orang Lain')
            ->assertDontSee('Rp777.000');

        Livewire::test(Dashboard::class)->assertViewHas('summary', fn ($s) => $s->totalIncome->amount === '10000000.00' && $s->totalLiabilities->amount === '2000000.00');
    }

    public function test_quick_entry_understands_and_posts_salary_activity_loan_and_repayment(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA', 1_000_000);
        $this->actingAs($user);

        Livewire::test(QuickEntry::class)
            ->set('text', 'gaji November 10jt')->call('interpret')
            ->assertSet('intent', 'SALARY_INCOME')
            ->assertSet('amount', '10000000')
            ->assertSet('destinationAccountId', $bca->id) // first asset account preselected
            ->call('save')->assertHasNoErrors()
            ->assertSet('intent', '')
            ->assertDispatched('entry-posted');

        Livewire::test(QuickEntry::class)
            ->set('text', 'hasil jualan kue dari Bu Rina 500rb')->call('interpret')
            ->assertSet('intent', 'ACTIVITY_INCOME')
            ->assertSet('activity', 'jualan kue')
            ->call('save')->assertHasNoErrors();

        Livewire::test(QuickEntry::class)
            ->set('text', 'pinjam dari Pak Budi 2jt')->call('interpret')
            ->assertSet('intent', 'LOAN_RECEIVED')->assertSet('counterparty', 'Pak Budi')
            ->call('save')->assertHasNoErrors();

        $loan = FinancialAccount::query()->where('user_id', $user->id)->where('account_type_code', 'LOAN')->sole();

        Livewire::test(QuickEntry::class)
            ->set('text', 'bayar hutang ke pak budi 1,5jt')->call('interpret')
            ->assertSet('intent', 'DEBT_REPAYMENT')
            ->assertSet('debtAccountId', $loan->id) // known party with one open debt
            ->assertSet('sourceAccountId', $bca->id)
            ->call('save')->assertHasNoErrors();

        $intents = Transaction::query()->where('user_id', $user->id)->where('channel', 'WEB')->orderBy('created_at')->orderBy('id')->pluck('intent')->map->value->all();
        $this->assertSame(['SALARY_INCOME', 'ACTIVITY_INCOME', 'LOAN_RECEIVED', 'DEBT_REPAYMENT'], $intents);

        $summary = $this->summary($user);
        $this->assertSame('10500000.00', $summary->totalIncome->amount, 'Loan is not income.');
        $this->assertSame('0.00', $summary->totalExpenses->amount, 'Repayment is not expense.');
        $this->assertSame('500000.00', $this->balanceOf($loan));
        $this->assertLedgerIntact($user);
    }

    public function test_quick_entry_cannot_use_another_users_account(): void
    {
        $victim = $this->provisionedUser();
        $victimBca = $this->openAccount($victim, 'BCA Korban', 'BANK', 'BCA', 5_000_000);
        $user = $this->provisionedUser();
        $this->openAccount($user, 'Cash', 'CASH', opening: 100_000);
        $this->actingAs($user);

        Livewire::test(QuickEntry::class)
            ->set('text', 'beli makan 50rb')->call('interpret')
            ->assertSet('intent', 'EXPENSE')
            ->set('sourceAccountId', $victimBca->id) // tampered in the browser
            ->call('save')
            ->assertHasErrors('save');

        $this->assertSame('5000000.00', $this->balanceOf($victimBca));
        $this->assertSame(0, Transaction::query()->where('user_id', $user->id)->where('type', TransactionType::EXPENSE)->count());
    }

    public function test_quick_entry_rejects_bad_amounts_and_future_dates(): void
    {
        $user = $this->provisionedUser();
        $this->openAccount($user, 'Cash', 'CASH', opening: 100_000);
        $this->actingAs($user);

        Livewire::test(QuickEntry::class)
            ->set('text', 'beli makan')->call('interpret')
            ->set('amount', 'abc')->call('save')->assertHasErrors('amount')
            ->set('amount', '10000')->set('transactionDate', now()->addDays(3)->toDateString())->call('save')->assertHasErrors('transactionDate');

        $this->assertSame(0, Transaction::query()->where('user_id', $user->id)->where('type', TransactionType::EXPENSE)->count());
    }

    public function test_accounts_page_creates_a_loan_owed_to_a_party(): void
    {
        $user = $this->provisionedUser();
        $this->actingAs($user);
        $this->get('/accounts')->assertOk();

        Livewire::test(Accounts::class)
            ->set('name', 'KTA Jago')->set('accountType', 'LOAN')->set('counterparty', 'Bank Jago')->set('openingBalance', '12jt')
            ->call('create')->assertHasNoErrors();

        $loan = FinancialAccount::query()->with('counterparty')->where('user_id', $user->id)->sole();
        $this->assertSame('Bank Jago', $loan->counterparty?->name);
        $this->assertSame('12000000.00', $this->balanceOf($loan));

        Livewire::test(Accounts::class)->set('name', 'Kartu')->set('accountType', 'CREDIT_CARD')->set('last4', '1234567890123456')
            ->call('create')->assertHasErrors('last4');
    }

    public function test_transactions_page_filters_and_reverses(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');
        $salary = $this->income($user, $bca, 5_000_000, 'SALARY');
        $this->income($user, $bca, 100_000, 'GIFT');
        $this->actingAs($user);

        $this->get('/transactions?intent=SALARY_INCOME')->assertOk()->assertSee('Rp5.000.000')->assertDontSee('Rp100.000');

        Livewire::test(Transactions::class)->call('reverse', $salary->id)->assertHasNoErrors();
        $this->assertSame(TransactionStatus::REVERSED, $salary->fresh()->status);
        $this->assertSame('100000.00', $this->balanceOf($bca));

        // Another user's transaction cannot be reversed.
        $other = $this->provisionedUser();
        $otherTx = $this->income($other, $this->openAccount($other, 'X', 'CASH'), 1_000, 'GIFT');
        Livewire::test(Transactions::class)->call('reverse', $otherTx->id)->assertHasErrors('reverse');
        $this->assertSame(TransactionStatus::POSTED, $otherTx->fresh()->status);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = $this->provisionedUser();
        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertFalse(Auth::check());
    }
}
