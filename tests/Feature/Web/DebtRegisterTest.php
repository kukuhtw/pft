<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Livewire\Debts;
use App\Livewire\QuickEntry;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Debts\Services\DebtRegisterService;
use App\Modules\Transactions\Models\Transaction;
use App\Modules\Transactions\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

/**
 * Catatan hutang: every loan is remembered with when it happened, who it is owed to and what it was for.
 */
final class DebtRegisterTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_quick_entry_records_when_from_whom_and_what_for(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');
        $this->actingAs($user);

        Livewire::test(QuickEntry::class)
            ->set('text', 'pinjam dari Pak Budi 2jt untuk bayar sekolah')->call('interpret')
            ->assertSet('intent', 'LOAN_RECEIVED')
            ->assertSet('counterparty', 'Pak Budi')
            ->assertSet('purpose', 'bayar sekolah')
            ->set('transactionDate', '2026-10-05')
            ->call('save')->assertHasNoErrors();

        // The user can type the purpose by hand too.
        Livewire::test(QuickEntry::class)
            ->set('text', 'pinjam dari Pak Budi 500rb')->call('interpret')
            ->set('purpose', 'servis motor')
            ->call('save')->assertHasNoErrors();

        $loans = Transaction::query()->where('user_id', $user->id)->where('intent', 'LOAN_RECEIVED')->orderBy('transaction_date')->get();
        $this->assertSame(['bayar sekolah', 'servis motor'], $loans->pluck('purpose')->all());
        $this->assertSame('2026-10-05', $loans->first()->transaction_date->toDateString());

        $row = app(DebtRegisterService::class)->register($user)[0];
        $this->assertSame('Pak Budi', $row['party']);
        $this->assertSame('2026-10-05', $row['since']->toDateString());
        $this->assertSame(['bayar sekolah', 'servis motor'], $row['purposes']);
        $this->assertSame('2500000.00', $row['remaining']->amount);
        $this->assertSame('2500000.00', $this->balanceOf($bca));
        $this->assertLedgerIntact($user);
    }

    public function test_purpose_is_only_kept_for_loans(): void
    {
        $user = $this->provisionedUser();
        $this->openAccount($user, 'Cash', 'CASH', opening: 100_000);
        $this->actingAs($user);

        Livewire::test(QuickEntry::class)
            ->set('text', 'beli buku untuk sekolah 50rb')->call('interpret')
            ->assertSet('intent', 'EXPENSE')
            ->set('purpose', 'ignored')
            ->call('save')->assertHasNoErrors();

        $this->assertNull(Transaction::query()->where('user_id', $user->id)->where('intent', 'EXPENSE')->sole()->purpose);
    }

    public function test_api_accepts_an_explicit_purpose_and_reads_it_from_the_text_otherwise(): void
    {
        $user = $this->provisionedUser();
        Sanctum::actingAs($user);
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');

        $this->postJson('/api/v1/transactions/loan-received', [
            'amount' => '1000000', 'destination_account_id' => $bca->id, 'counterparty' => 'Bu Rina', 'purpose' => 'Modal usaha',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.purpose', 'Modal usaha');

        $this->postJson('/api/v1/transactions/loan-received', [
            'amount' => '300000', 'destination_account_id' => $bca->id, 'counterparty' => 'Bu Rina', 'description' => 'ngutang buat keperluan beli obat',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.purpose', 'beli obat');
    }

    public function test_page_lists_loans_and_repayments_and_hides_paid_off_debts_by_default(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA', 5_000_000);
        $this->actingAs($user);

        foreach (['pinjam dari Pak Budi 2jt untuk renovasi dapur', 'pinjam dari Bu Rina 1jt buat beli laptop'] as $text) {
            Livewire::test(QuickEntry::class)->set('text', $text)->call('interpret')->call('save')->assertHasNoErrors();
        }
        Livewire::test(QuickEntry::class)->set('text', 'bayar hutang ke Bu Rina 1jt')->call('interpret')->call('save')->assertHasNoErrors();

        $this->get(route('debts'))->assertOk()
            ->assertSee('Catatan Hutang')
            ->assertSee('Pak Budi')->assertSee('renovasi dapur')
            ->assertDontSee('beli laptop'); // Bu Rina is paid off

        Livewire::test(Debts::class)
            ->assertSee('Rp2.000.000', false)
            ->set('show', 'semua')
            ->assertSee('Bu Rina')->assertSee('beli laptop')->assertSee('Lunas');

        $this->assertSame('7000000.00', $this->balanceOf($bca));
    }

    public function test_reversed_loans_drop_out_and_other_users_debts_are_never_shown(): void
    {
        $other = $this->provisionedUser();
        $otherBca = $this->openAccount($other, 'BCA Lain', 'BANK', 'BCA');
        $this->actingAs($other);
        Livewire::test(QuickEntry::class)->set('text', 'pinjam dari Pak Rahasia 9jt untuk urusan pribadi')->call('interpret')
            ->set('destinationAccountId', $otherBca->id)->call('save')->assertHasNoErrors();
        auth()->logout();

        $user = $this->provisionedUser();
        $this->openAccount($user, 'BCA', 'BANK', 'BCA');
        $this->actingAs($user);
        Livewire::test(QuickEntry::class)->set('text', 'pinjam dari Andi 100rb untuk ongkos')->call('interpret')->call('save')->assertHasNoErrors();

        $loan = Transaction::query()->where('user_id', $user->id)->where('intent', 'LOAN_RECEIVED')->sole();
        app(TransactionService::class)->reverse($user, $loan->id, 'salah catat');

        Livewire::test(Debts::class)->set('show', 'semua')
            ->assertDontSee('Pak Rahasia')->assertDontSee('urusan pribadi')
            ->assertDontSee('ongkos');

        $this->assertSame([], app(DebtRegisterService::class)->register($user, includeSettled: false));
        $this->assertSame(1, FinancialAccount::query()->where('user_id', $user->id)->where('account_type_code', 'LOAN')->count());
    }

    public function test_guests_cannot_open_the_debt_register(): void
    {
        $this->get('/hutang')->assertRedirect('/login');
    }
}
