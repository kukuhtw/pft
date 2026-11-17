<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Models\User;
use App\Modules\Ledger\Enums\LedgerAccountClass;
use App\Modules\Reporting\Data\FinancialSummary;
use App\Modules\Shared\Domain\Money;
use App\Modules\Transactions\Enums\TransactionStatus;
use App\Modules\Transactions\Enums\TransactionType;
use App\Modules\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Period summary computed exclusively from posted journal lines (see docs §2.3 for definitions).
 */
final class FinancialSummaryService
{
    public function summarize(User $user, CarbonImmutable $from, CarbonImmutable $to, string $currency = 'IDR'): FinancialSummary
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        // Flows within the period, by ledger class. Cash-flow columns exclude ADJUSTMENT transactions
        // (opening balances / corrections) and their reversals.
        $flows = $this->lines($user, $currency)
            ->whereBetween('je.entry_date', [$fromDate, $toDate])
            ->groupBy('la.class')
            ->select('la.class')
            ->selectRaw("SUM(CASE WHEN jl.direction = 'DEBIT' THEN jl.amount ELSE 0 END) AS debit")
            ->selectRaw("SUM(CASE WHEN jl.direction = 'CREDIT' THEN jl.amount ELSE 0 END) AS credit")
            ->selectRaw("SUM(CASE WHEN jl.direction = 'DEBIT' AND (t.type IS NULL OR t.type <> 'ADJUSTMENT') THEN jl.amount ELSE 0 END) AS cf_debit")
            ->selectRaw("SUM(CASE WHEN jl.direction = 'CREDIT' AND (t.type IS NULL OR t.type <> 'ADJUSTMENT') THEN jl.amount ELSE 0 END) AS cf_credit")
            ->get()
            ->keyBy('class');

        // Cumulative balances at period end, by ledger class.
        $positions = $this->lines($user, $currency)
            ->where('je.entry_date', '<=', $toDate)
            ->groupBy('la.class')
            ->select('la.class')
            ->selectRaw("SUM(CASE WHEN jl.direction = 'DEBIT' THEN jl.amount ELSE 0 END) AS debit")
            ->selectRaw("SUM(CASE WHEN jl.direction = 'CREDIT' THEN jl.amount ELSE 0 END) AS credit")
            ->get()
            ->keyBy('class');

        $net = static function ($rows, LedgerAccountClass $class, string $plus, string $minus) use ($currency): Money {
            $row = $rows->get($class->value);

            return $row === null
                ? Money::zero($currency)
                : Money::fromDatabase($row->{$plus}, $currency)->minus(Money::fromDatabase($row->{$minus}, $currency));
        };

        $income = $net($flows, LedgerAccountClass::INCOME, 'credit', 'debit');
        $expenses = $net($flows, LedgerAccountClass::EXPENSE, 'debit', 'credit');
        $netCashFlow = $net($flows, LedgerAccountClass::ASSET, 'cf_debit', 'cf_credit');
        $assets = $net($positions, LedgerAccountClass::ASSET, 'debit', 'credit');
        $liabilities = $net($positions, LedgerAccountClass::LIABILITY, 'credit', 'debit');

        $transfers = Money::fromDatabase(
            Transaction::query()
                ->where('user_id', $user->id)
                ->where('type', TransactionType::INTERNAL_TRANSFER)
                ->where('status', TransactionStatus::POSTED)
                ->where('currency_code', $currency)
                ->whereBetween('transaction_date', [$fromDate, $toDate])
                ->sum('amount'),
            $currency,
        );

        return new FinancialSummary(
            from: $from,
            to: $to,
            totalIncome: $income,
            totalExpenses: $expenses,
            netIncome: $income->minus($expenses),
            totalInternalTransfers: $transfers,
            totalAssets: $assets,
            totalLiabilities: $liabilities,
            netWorth: $assets->minus($liabilities),
            netCashFlow: $netCashFlow,
        );
    }

    private function lines(User $user, string $currency): Builder
    {
        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
            ->leftJoin('transactions as t', 't.id', '=', 'je.transaction_id')
            ->where('jl.user_id', $user->id)
            ->where('je.user_id', $user->id)
            ->where('je.currency_code', $currency);
    }
}
