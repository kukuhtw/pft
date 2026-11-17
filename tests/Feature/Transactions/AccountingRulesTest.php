<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Modules\Ledger\Enums\JournalEntryType;
use App\Modules\Ledger\Exceptions\ImmutableRecordException;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Transactions\Enums\TransactionStatus;
use App\Modules\Transactions\Enums\TransactionType;
use App\Modules\Transactions\Exceptions\AccountNotUsableException;
use App\Modules\Transactions\Exceptions\IdempotencyConflictException;
use App\Modules\Transactions\Exceptions\InsufficientFundsException;
use App\Modules\Transactions\Exceptions\InvalidTransactionException;
use App\Modules\Transactions\Exceptions\TransactionNotReversibleException;
use App\Modules\Transactions\Models\Transaction;
use App\Modules\Transactions\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

/**
 * Master-prompt §19 testing requirements 1–7, 9, 10, 15 plus overdraft / validation guards.
 */
final class AccountingRulesTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    public function test_1_income_increases_assets(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');

        $this->income($user, $bca, 5_000_000);

        $this->assertSame('5000000.00', $this->balanceOf($bca));
        $this->assertSame('5000000.00', $this->summary($user)->totalAssets->amount);
        $this->assertSame('5000000.00', $this->summary($user)->totalIncome->amount);
    }

    public function test_2_expense_decreases_assets(): void
    {
        $user = $this->provisionedUser();
        $cash = $this->openAccount($user, 'Cash', 'CASH', opening: 100_000);

        $this->expense($user, $cash, 35_000);

        $this->assertSame('65000.00', $this->balanceOf($cash));
        $summary = $this->summary($user);
        $this->assertSame('65000.00', $summary->totalAssets->amount);
        $this->assertSame('35000.00', $summary->totalExpenses->amount);
    }

    public function test_3_internal_transfer_preserves_total_assets(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA', 3_000_000);
        $bni = $this->openAccount($user, 'BNI', 'BANK', 'BNI');
        $before = $this->summary($user)->totalAssets->amount;

        $tx = $this->transfer($user, $bca, $bni, 1_250_000);

        $this->assertSame(TransactionType::INTERNAL_TRANSFER, $tx->type);
        $this->assertSame($before, $this->summary($user)->totalAssets->amount);
        $this->assertSame('1750000.00', $this->balanceOf($bca));
        $this->assertSame('1250000.00', $this->balanceOf($bni));
        $summary = $this->summary($user);
        $this->assertSame('0.00', $summary->totalIncome->amount);
        $this->assertSame('0.00', $summary->totalExpenses->amount);
    }

    public function test_4_cash_withdrawal_is_an_internal_transfer(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA', 1_000_000);
        $cash = $this->openAccount($user, 'Cash', 'CASH');

        $tx = $this->transfer($user, $bca, $cash, 500_000);

        $this->assertSame(TransactionType::INTERNAL_TRANSFER, $tx->type);
        $this->assertSame('500000.00', $this->balanceOf($cash));
        $this->assertSame('0.00', $this->summary($user)->totalExpenses->amount);
        $this->assertSame('500000.00', $this->summary($user)->totalInternalTransfers->amount);
    }

    public function test_5_credit_card_purchase_increases_liabilities(): void
    {
        $user = $this->provisionedUser();
        $card = $this->openAccount($user, 'Kartu Kredit BCA', 'CREDIT_CARD', 'BCA');

        $tx = $this->expense($user, $card, 750_000, 'SHOPPING');

        $this->assertSame(TransactionType::EXPENSE, $tx->type);
        $this->assertSame('750000.00', $this->balanceOf($card)); // amount owed
        $summary = $this->summary($user);
        $this->assertSame('750000.00', $summary->totalLiabilities->amount);
        $this->assertSame('750000.00', $summary->totalExpenses->amount);
        $this->assertSame('-750000.00', $summary->netWorth->amount);
        $this->assertSame('0.00', $summary->netCashFlow->amount, 'Card purchase must not move cash.');
    }

    public function test_6_credit_card_payment_reduces_liabilities_without_new_expense(): void
    {
        $user = $this->provisionedUser();
        $mandiri = $this->openAccount($user, 'Mandiri', 'BANK', 'MANDIRI', 5_000_000);
        $card = $this->openAccount($user, 'Kartu Kredit BCA', 'CREDIT_CARD', 'BCA');
        $this->expense($user, $card, 2_000_000, 'SHOPPING');

        $payment = $this->transfer($user, $mandiri, $card, 2_000_000);

        $this->assertSame(TransactionType::LIABILITY_PAYMENT, $payment->type);
        $this->assertSame('0.00', $this->balanceOf($card));
        $this->assertSame('3000000.00', $this->balanceOf($mandiri));
        $summary = $this->summary($user);
        $this->assertSame('0.00', $summary->totalLiabilities->amount);
        $this->assertSame('2000000.00', $summary->totalExpenses->amount, 'Payment must not be double-counted as expense.');
    }

    public function test_7_loan_principal_repayment_is_not_double_counted_as_expense(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');
        $loan = $this->openAccount($user, 'Pinjaman KTA', 'LOAN');

        $disbursement = $this->transfer($user, $loan, $bca, 10_000_000);
        $this->assertSame(TransactionType::LIABILITY_DRAWDOWN, $disbursement->type);
        $this->assertSame('10000000.00', $this->balanceOf($loan));

        $principal = $this->transfer($user, $bca, $loan, 1_000_000);
        $this->expense($user, $bca, 150_000, 'INTEREST_FEES'); // interest is an expense

        $this->assertSame(TransactionType::LIABILITY_PAYMENT, $principal->type);
        $this->assertSame('9000000.00', $this->balanceOf($loan));
        $this->assertSame('8850000.00', $this->balanceOf($bca));
        $summary = $this->summary($user);
        $this->assertSame('150000.00', $summary->totalExpenses->amount, 'Only interest is an expense.');
        $this->assertSame('0.00', $summary->totalIncome->amount, 'Loan disbursement is not income.');
        $this->assertSame('9000000.00', $summary->totalLiabilities->amount);
        $this->assertSame('-150000.00', $summary->netWorth->amount);
        $this->assertLedgerIntact($user);
    }

    public function test_9_duplicate_requests_with_same_idempotency_key_post_once(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');

        $first = $this->income($user, $bca, 1_000_000, key: 'salary-2026-11');
        $second = $this->income($user, $bca, 1_000_000, key: 'salary-2026-11');

        $this->assertSame($first->id, $second->id);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame('1000000.00', $this->balanceOf($bca));
        $this->assertSame(1, Transaction::query()->where('type', TransactionType::INCOME)->count());
    }

    public function test_9b_reusing_idempotency_key_with_different_payload_is_rejected(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');
        $this->income($user, $bca, 1_000_000, key: 'key-12345678');

        $this->expectException(IdempotencyConflictException::class);
        $this->income($user, $bca, 2_000_000, key: 'key-12345678');
    }

    public function test_10_reversal_preserves_audit_history(): void
    {
        $user = $this->provisionedUser();
        $cash = $this->openAccount($user, 'Cash', 'CASH', opening: 100_000);
        $tx = $this->expense($user, $cash, 35_000);
        $original = JournalEntry::query()->where('transaction_id', $tx->id)->sole();

        $reversed = app(TransactionService::class)->reverse($user, $tx->id, 'Salah input');

        $this->assertSame(TransactionStatus::REVERSED, $reversed->status);
        $this->assertSame('100000.00', $this->balanceOf($cash));
        $this->assertSame('0.00', $this->summary($user)->totalExpenses->amount);

        // Original entry untouched, mirror entry appended.
        $entries = JournalEntry::query()->where('transaction_id', $tx->id)->with('lines')->orderBy('posted_at')->get();
        $this->assertCount(2, $entries);
        $this->assertSame($original->id, $entries->firstWhere('entry_type', JournalEntryType::STANDARD)->id);
        $reversal = $entries->firstWhere('entry_type', JournalEntryType::REVERSAL);
        $this->assertSame($original->id, $reversal->reverses_entry_id);
        $this->assertSame('35000.00', $original->fresh()->total_debit);
        $this->assertSame(4, JournalLine::query()->whereIn('journal_entry_id', $entries->pluck('id'))->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'transaction.reversed', 'subject_id' => $tx->id]);

        // Cannot reverse twice.
        $this->expectException(TransactionNotReversibleException::class);
        app(TransactionService::class)->reverse($user, $tx->id);
    }

    public function test_15_posted_journals_and_transactions_are_immutable(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA');
        $tx = $this->income($user, $bca, 1_000_000);
        $entry = JournalEntry::query()->where('transaction_id', $tx->id)->sole();
        $line = JournalLine::query()->where('journal_entry_id', $entry->id)->firstOrFail();

        foreach ([
            static fn () => $entry->update(['description' => 'tampered']),
            static fn () => $entry->delete(),
            static fn () => $line->update(['amount' => '1.00']),
            static fn () => $line->delete(),
            static fn () => $tx->update(['amount' => '1.00']),
            static fn () => $tx->delete(),
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("Mutation #{$i} was not blocked.");
            } catch (ImmutableRecordException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('1000000.00', $this->balanceOf($bca));
        $this->assertLedgerIntact($user);
    }

    public function test_expense_exceeding_balance_is_rejected_and_rolled_back(): void
    {
        $user = $this->provisionedUser();
        $cash = $this->openAccount($user, 'Cash', 'CASH', opening: 10_000);

        try {
            $this->expense($user, $cash, 35_000);
            $this->fail('Overdraft was allowed.');
        } catch (InsufficientFundsException) {
            $this->assertSame('10000.00', $this->balanceOf($cash));
            $this->assertSame(0, Transaction::query()->where('type', TransactionType::EXPENSE)->count());
            $this->assertSame(1, JournalEntry::query()->count()); // only the opening balance
        }

        $this->assertLedgerIntact($user);
    }

    public function test_account_flagged_for_overdraft_may_go_negative(): void
    {
        $user = $this->provisionedUser();
        $bank = $this->openAccount($user, 'Jago', 'BANK', 'JAGO', allowNegative: true);

        $this->expense($user, $bank, 50_000);

        $this->assertSame('-50000.00', $this->balanceOf($bank));
    }

    public function test_transfer_to_the_same_account_is_rejected(): void
    {
        $user = $this->provisionedUser();
        $bca = $this->openAccount($user, 'BCA', 'BANK', 'BCA', 100_000);

        $this->expectException(InvalidTransactionException::class);
        $this->transfer($user, $bca, $bca, 1_000);
    }

    public function test_opening_balance_is_excluded_from_income_and_cash_flow(): void
    {
        $user = $this->provisionedUser();
        $this->openAccount($user, 'BCA', 'BANK', 'BCA', 2_500_000);

        $summary = $this->summary($user);
        $this->assertSame('2500000.00', $summary->totalAssets->amount);
        $this->assertSame('0.00', $summary->totalIncome->amount);
        $this->assertSame('0.00', $summary->netCashFlow->amount);
    }

    public function test_12_user_cannot_post_against_another_users_account(): void
    {
        $alice = $this->provisionedUser();
        $bob = $this->provisionedUser();
        $aliceBca = $this->openAccount($alice, 'BCA', 'BANK', 'BCA', 1_000_000);

        try {
            $this->expense($bob, $aliceBca, 100_000);
            $this->fail('Cross-tenant posting was allowed.');
        } catch (AccountNotUsableException) {
            $this->assertSame('1000000.00', $this->balanceOf($aliceBca));
        }

        $this->assertLedgerIntact();
    }
}
