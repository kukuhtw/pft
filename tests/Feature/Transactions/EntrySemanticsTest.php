<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Counterparties\Models\Counterparty;
use App\Modules\Transactions\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

/**
 * Every submitted entry must be understood as one of: salary, income from an activity, loan received
 * from a party, or payment to a party for a specific debt — without inflating income or expenses.
 */
final class EntrySemanticsTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    public function test_salary_is_understood_from_the_description_and_linked_to_the_employer(): void
    {
        $user = $this->provisionedUser();
        Sanctum::actingAs($user);
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');

        $this->post_('income', ['amount' => '10000000', 'destination_account_id' => $bca->id, 'description' => 'Gaji November', 'counterparty' => 'PT Maju Jaya'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'INCOME')
            ->assertJsonPath('data.intent', 'SALARY_INCOME')
            ->assertJsonPath('data.category.code', 'SALARY') // inferred: no category_id was sent
            ->assertJsonPath('data.counterparty.name', 'PT Maju Jaya')
            ->assertJsonPath('data.counterparty.kind', 'ORGANIZATION')
            ->assertJsonPath('data.interpretation.confidence', 'MEDIUM');

        $this->assertSame('10000000.00', $this->summary($user)->totalIncome->amount);
    }

    public function test_income_from_an_activity_records_the_activity(): void
    {
        $user = $this->provisionedUser();
        Sanctum::actingAs($user);
        $cash = $this->openAccount($user, 'Cash', 'CASH');

        $this->post_('income', ['amount' => '500000', 'destination_account_id' => $cash->id, 'description' => 'hasil jualan kue dari Bu Rina'])
            ->assertCreated()
            ->assertJsonPath('data.intent', 'ACTIVITY_INCOME')
            ->assertJsonPath('data.category.code', 'BUSINESS')
            ->assertJsonPath('data.activity', 'jualan kue')
            ->assertJsonPath('data.counterparty', null) // unknown name: kept as a guess, no row created
            ->assertJsonPath('data.interpretation.counterparty_guess', 'Bu Rina');

        $this->assertSame(0, Counterparty::query()->count());

        // Explicit category + explicit activity always win over the text.
        $this->post_('income', ['amount' => '750000', 'destination_account_id' => $cash->id, 'category_id' => $this->category($user, 'FREELANCE')->id, 'activity' => 'Desain logo', 'description' => 'transfer klien'])
            ->assertCreated()
            ->assertJsonPath('data.intent', 'ACTIVITY_INCOME')
            ->assertJsonPath('data.activity', 'Desain logo')
            ->assertJsonPath('data.interpretation.confidence', 'HIGH');
    }

    public function test_loan_received_from_a_party_is_a_liability_not_income(): void
    {
        $user = $this->provisionedUser();
        Sanctum::actingAs($user);
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');

        $first = $this->post_('loan-received', ['amount' => '2000000', 'destination_account_id' => $bca->id, 'counterparty' => 'Pak Budi', 'description' => 'pinjam buat modal'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'LIABILITY_DRAWDOWN')
            ->assertJsonPath('data.intent', 'LOAN_RECEIVED')
            ->assertJsonPath('data.counterparty.name', 'Pak Budi')
            ->assertJsonPath('data.counterparty.kind', 'PERSON');

        $loan = FinancialAccount::query()->findOrFail($first->json('data.debt_account_id'));
        $this->assertSame('Pinjaman Pak Budi', $loan->name);
        $this->assertSame('LOAN', $loan->account_type_code);

        // Same party written differently → same debt account.
        $this->post_('loan-received', ['amount' => '1000000', 'destination_account_id' => $bca->id, 'counterparty' => 'budi'])
            ->assertCreated()
            ->assertJsonPath('data.debt_account_id', $loan->id);

        $summary = $this->summary($user);
        $this->assertSame('0.00', $summary->totalIncome->amount, 'A loan must never be counted as income.');
        $this->assertSame('3000000.00', $summary->totalLiabilities->amount);
        $this->assertSame('3000000.00', $this->balanceOf($loan));
        $this->assertLedgerIntact($user);
    }

    public function test_payment_to_a_party_for_a_specific_debt(): void
    {
        $user = $this->provisionedUser();
        Sanctum::actingAs($user);
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA', 5_000_000);
        $loanId = $this->post_('loan-received', ['amount' => '2000000', 'destination_account_id' => $bca->id, 'counterparty' => 'Pak Budi'])->json('data.debt_account_id');

        $this->post_('debt-payment', ['amount' => '500000', 'source_account_id' => $bca->id, 'counterparty' => 'Budi', 'description' => 'bayar hutang ke Pak Budi'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'LIABILITY_PAYMENT')
            ->assertJsonPath('data.intent', 'DEBT_REPAYMENT')
            ->assertJsonPath('data.debt_account_id', $loanId)
            ->assertJsonPath('data.counterparty.name', 'Pak Budi')
            ->assertJsonPath('data.interpretation.warnings', []);

        $summary = $this->summary($user);
        $this->assertSame('0.00', $summary->totalExpenses->amount, 'A debt repayment must never be counted as expense.');
        $this->assertSame('1500000.00', $summary->totalLiabilities->amount);

        // History per party.
        $budi = Counterparty::query()->where('user_id', $user->id)->sole();
        $this->getJson("/api/v1/transactions?counterparty_id={$budi->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/transactions?intent=DEBT_REPAYMENT')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/counterparties')->assertOk()
            ->assertJsonPath('data.0.name', 'Pak Budi')
            ->assertJsonPath('data.0.debt_accounts.0.balance.amount', '1500000.00');
        $this->assertLedgerIntact($user);
    }

    public function test_debt_payment_requires_an_existing_unambiguous_debt(): void
    {
        $user = $this->provisionedUser();
        Sanctum::actingAs($user);
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA', 5_000_000);

        $this->post_('debt-payment', ['amount' => '100000', 'source_account_id' => $bca->id, 'counterparty' => 'Orang Asing'])
            ->assertStatus(422)->assertJsonPath('error.code', 'DEBT_ACCOUNT_NOT_FOUND');
        $this->assertSame(0, Counterparty::query()->count(), 'Failed request must not leave a counterparty behind.');

        // Two separate debts to the same lender → the client must choose.
        $this->postJson('/api/v1/accounts', ['name' => 'KTA Jago', 'account_type' => 'LOAN', 'counterparty' => 'Bank Jago'])->assertCreated();
        $card = $this->postJson('/api/v1/accounts', ['name' => 'Kartu Kredit Jago', 'account_type' => 'CREDIT_CARD', 'counterparty' => 'Bank Jago'])
            ->assertCreated()->assertJsonPath('data.counterparty.name', 'Bank Jago')->json('data.id');

        $this->post_('debt-payment', ['amount' => '100000', 'source_account_id' => $bca->id, 'counterparty' => 'Bank Jago'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DEBT_ACCOUNT_AMBIGUOUS')
            ->assertJsonCount(2, 'error.details.debt_accounts');

        // Paying a debt from a liability account is rejected; paying into an asset is not a debt payment.
        $this->post_('debt-payment', ['amount' => '100000', 'source_account_id' => $card, 'debt_account_id' => $card])
            ->assertStatus(422)->assertJsonPath('error.code', 'TRANSACTION_INVALID');
        $this->post_('debt-payment', ['amount' => '100000', 'source_account_id' => $bca->id, 'debt_account_id' => $bca->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'TRANSACTION_INVALID');
    }

    public function test_debt_account_is_tenant_scoped(): void
    {
        $alice = $this->provisionedUser();
        $aliceLoan = $this->openAccount($alice, 'Pinjaman Alice', 'LOAN');
        $bob = $this->provisionedUser();
        $bobBca = $this->openAccount($bob, 'BCA', 'BANK', 'BCA', 1_000_000);
        Sanctum::actingAs($bob);

        $this->post_('debt-payment', ['amount' => '100000', 'source_account_id' => $bobBca->id, 'debt_account_id' => $aliceLoan->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'ACCOUNT_NOT_USABLE');
    }

    public function test_loan_received_is_idempotent_and_opens_one_debt_account(): void
    {
        $user = $this->provisionedUser();
        Sanctum::actingAs($user);
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');
        $payload = ['amount' => '2000000', 'destination_account_id' => $bca->id, 'counterparty' => 'Pak Budi'];
        $headers = ['Idempotency-Key' => 'loan-001'];

        $id = $this->postJson('/api/v1/transactions/loan-received', $payload, $headers)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/transactions/loan-received', $payload, $headers)
            ->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $id);

        $this->assertSame(1, FinancialAccount::query()->where('user_id', $user->id)->where('account_type_code', 'LOAN')->count());
        $this->assertSame('2000000.00', $this->summary($user)->totalLiabilities->amount);
    }

    public function test_text_that_contradicts_the_operation_is_flagged(): void
    {
        $user = $this->provisionedUser();
        Sanctum::actingAs($user);
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA', 1_000_000);

        $this->post_('income', ['amount' => '2000000', 'destination_account_id' => $bca->id, 'description' => 'pinjam dari Budi'])
            ->assertCreated()
            ->assertJsonPath('data.intent', 'OTHER_INCOME')
            ->assertJsonPath('data.interpretation.warnings', ['TEXT_SUGGESTS_LOAN_RECEIVED']);

        $this->post_('expense', ['amount' => '100000', 'source_account_id' => $bca->id, 'description' => 'bayar hutang ke Budi'])
            ->assertCreated()
            ->assertJsonPath('data.intent', 'EXPENSE')
            ->assertJsonPath('data.interpretation.warnings', ['TEXT_SUGGESTS_DEBT_REPAYMENT']);
    }

    public function test_credit_card_payment_via_transfer_is_a_debt_repayment(): void
    {
        $user = $this->provisionedUser();
        $mandiri = $this->openAccount($user, 'Mandiri', 'BANK', 'MANDIRI', 5_000_000);
        $card = $this->openAccount($user, 'Kartu Kredit BCA', 'CREDIT_CARD', 'BCA');
        $this->expense($user, $card, 1_000_000, 'SHOPPING');

        $tx = $this->transfer($user, $mandiri, $card, 1_000_000);

        $this->assertSame('DEBT_REPAYMENT', $tx->intent?->value);
        $this->assertSame('Bank Central Asia', $tx->metadata['interpretation']['counterparty_guess']);
    }

    public function test_interpret_turns_free_text_into_a_confirmable_request(): void
    {
        $user = $this->provisionedUser();
        Sanctum::actingAs($user);
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA', 5_000_000);
        $loanId = $this->post_('loan-received', ['amount' => '2000000', 'destination_account_id' => $bca->id, 'counterparty' => 'Pak Budi'])->json('data.debt_account_id');
        $before = Transaction::query()->count();

        $this->postJson('/api/v1/transactions/interpret', ['text' => 'bayar hutang ke pak budi 1jt'])
            ->assertOk()
            ->assertJsonPath('data.intent', 'DEBT_REPAYMENT')
            ->assertJsonPath('data.amount.amount', '1000000.00')
            ->assertJsonPath('data.counterparty.known', true)
            ->assertJsonPath('data.counterparty.name', 'Pak Budi')
            ->assertJsonPath('data.counterparty.debt_accounts.0.id', $loanId)
            ->assertJsonPath('data.suggested_request.path', '/api/v1/transactions/debt-payment')
            ->assertJsonPath('data.suggested_request.body.debt_account_id', $loanId)
            ->assertJsonPath('data.suggested_request.missing', ['source_account_id'])
            ->assertJsonPath('data.requires_confirmation', true);

        $this->postJson('/api/v1/transactions/interpret', ['text' => 'gaji november 10 juta'])
            ->assertOk()
            ->assertJsonPath('data.intent', 'SALARY_INCOME')
            ->assertJsonPath('data.category.code', 'SALARY')
            ->assertJsonPath('data.suggested_request.path', '/api/v1/transactions/income');

        $this->postJson('/api/v1/transactions/interpret', ['text' => 'hasil jualan kue 500rb'])
            ->assertOk()
            ->assertJsonPath('data.intent', 'ACTIVITY_INCOME')
            ->assertJsonPath('data.activity', 'jualan kue');

        $this->assertSame($before, Transaction::query()->count(), 'Interpret must not post anything.');
    }

    private function post_(string $kind, array $payload): TestResponse
    {
        return $this->postJson("/api/v1/transactions/{$kind}", $payload, ['Idempotency-Key' => (string) Str::uuid()]);
    }
}
