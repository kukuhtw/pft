<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Transactions\Enums\PaymentMethod;
use App\Modules\Transactions\Enums\TransactionType;
use App\Modules\Transactions\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsFinance;
use Tests\TestCase;

/**
 * Master-prompt §4 acceptance scenario: Rp10,000,000 salary distributed across accounts.
 */
final class SalarySimulationTest extends TestCase
{
    use BuildsFinance;
    use RefreshDatabase;

    private User $user;

    /** @var array<string, FinancialAccount> */
    private array $acc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->provisionedUser();
        $this->acc = [
            'BCA' => $this->openAccount($this->user, 'BCA Payroll', 'BANK', 'BCA'),
            'BNI' => $this->openAccount($this->user, 'BNI Savings', 'BANK', 'BNI'),
            'CASH' => $this->openAccount($this->user, 'Cash Wallet', 'CASH'),
            'MANDIRI' => $this->openAccount($this->user, 'Mandiri', 'BANK', 'MANDIRI'),
            'CIMB' => $this->openAccount($this->user, 'CIMB Niaga', 'BANK', 'CIMB'),
        ];
    }

    public function test_salary_distribution_scenario_produces_expected_balances_and_summary(): void
    {
        $this->runScenario();

        // Expected balances
        $this->assertSame('4000000.00', $this->balanceOf($this->acc['BCA']));
        $this->assertSame('1990000.00', $this->balanceOf($this->acc['BNI']));
        $this->assertSame('1965000.00', $this->balanceOf($this->acc['CASH']));
        $this->assertSame('1000000.00', $this->balanceOf($this->acc['MANDIRI']));
        $this->assertSame('1000000.00', $this->balanceOf($this->acc['CIMB']));

        // Expected financial summary (computed from posted journal lines)
        $summary = $this->summary($this->user);
        $this->assertSame('10000000.00', $summary->totalIncome->amount);
        $this->assertSame('45000.00', $summary->totalExpenses->amount);
        $this->assertSame('6000000.00', $summary->totalInternalTransfers->amount);
        $this->assertSame('9955000.00', $summary->totalAssets->amount);
        $this->assertSame('9955000.00', $summary->netCashFlow->amount);
        $this->assertSame('9955000.00', $summary->netIncome->amount);
        $this->assertSame('0.00', $summary->totalLiabilities->amount);
        $this->assertSame('9955000.00', $summary->netWorth->amount);

        $this->assertLedgerIntact($this->user);
    }

    public function test_internal_transfers_do_not_touch_income_or_expense_accounts(): void
    {
        $this->runScenario();

        $transferEntries = JournalEntry::query()
            ->whereIn('transaction_id', Transaction::query()->where('type', TransactionType::INTERNAL_TRANSFER)->select('id'))
            ->with('lines.ledgerAccount')
            ->get();

        $this->assertCount(4, $transferEntries);

        foreach ($transferEntries as $entry) {
            foreach ($entry->lines as $line) {
                $this->assertSame('ASSET', $line->ledgerAccount->class->value, 'Transfer touched a non-asset account.');
            }
        }
    }

    public function test_every_posted_journal_is_balanced(): void
    {
        $this->runScenario();

        $entries = JournalEntry::query()->with('lines')->get();
        $this->assertCount(7, $entries);

        foreach ($entries as $entry) {
            $debit = '0.00';
            $credit = '0.00';
            foreach ($entry->lines as $line) {
                $line->direction === EntryDirection::DEBIT
                    ? $debit = bcadd($debit, $line->amount, 2)
                    : $credit = bcadd($credit, $line->amount, 2);
            }

            $this->assertSame($debit, $credit, "Entry {$entry->id} is unbalanced.");
            $this->assertSame($entry->total_debit, $debit);
            $this->assertSame($entry->total_credit, $credit);
        }
    }

    private function runScenario(): void
    {
        $this->income($this->user, $this->acc['BCA'], 10_000_000, 'SALARY', description: 'Gaji bulanan');
        $this->transfer($this->user, $this->acc['BCA'], $this->acc['BNI'], 2_000_000);
        $this->transfer($this->user, $this->acc['BCA'], $this->acc['CASH'], 2_000_000); // cash withdrawal
        $this->transfer($this->user, $this->acc['BCA'], $this->acc['MANDIRI'], 1_000_000);
        $this->transfer($this->user, $this->acc['BCA'], $this->acc['CIMB'], 1_000_000);
        $this->expense($this->user, $this->acc['CASH'], 35_000, 'FOOD', 'Sate', PaymentMethod::CASH);
        $this->expense($this->user, $this->acc['BNI'], 10_000, 'FOOD', 'Minuman', PaymentMethod::QRIS);
    }
}
