<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Models\User;
use App\Modules\Categories\Enums\CategoryKind;
use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Ledger\Enums\LedgerAccountClass;
use App\Modules\Shared\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Period reports read straight from posted journal lines (docs §2.3), so a reversed entry nets to zero
 * in its original period and transfers / loans / debt repayments never appear as income or expense.
 */
final class ReportService
{
    /**
     * Net amount per income or expense category in the period, largest first. Zero rows are omitted.
     *
     * @return list<array{category: TransactionCategory, total: Money}>
     */
    public function byCategory(User $user, CategoryKind $kind, CarbonImmutable $from, CarbonImmutable $to, string $currency = 'IDR'): array
    {
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('transaction_categories as tc', 'tc.ledger_account_id', '=', 'jl.ledger_account_id')
            ->where('jl.user_id', $user->id)
            ->where('je.user_id', $user->id)
            ->where('tc.user_id', $user->id)
            ->where('tc.kind', $kind->value)
            ->where('je.currency_code', $currency)
            ->whereBetween('je.entry_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('tc.id')
            ->select('tc.id')
            ->selectRaw("SUM(CASE WHEN jl.direction = 'DEBIT' THEN jl.amount ELSE 0 END) AS debit")
            ->selectRaw("SUM(CASE WHEN jl.direction = 'CREDIT' THEN jl.amount ELSE 0 END) AS credit")
            ->get();

        $categories = TransactionCategory::query()->whereIn('id', $rows->pluck('id'))->get()->keyBy('id');
        $result = [];

        foreach ($rows as $row) {
            $debit = Money::fromDatabase($row->debit, $currency);
            $credit = Money::fromDatabase($row->credit, $currency);
            $total = $kind === CategoryKind::EXPENSE ? $debit->minus($credit) : $credit->minus($debit);

            if (! $total->isZero() && isset($categories[$row->id])) {
                $result[] = ['category' => $categories[$row->id], 'total' => $total];
            }
        }

        usort($result, static fn (array $a, array $b): int => $b['total']->compare($a['total']));

        return $result;
    }

    /**
     * Income and expense per calendar month for the $months months ending with $endMonth (inclusive).
     *
     * @return list<array{month: CarbonImmutable, income: Money, expense: Money, net: Money}>
     */
    public function monthlyTrend(User $user, CarbonImmutable $endMonth, int $months = 12, string $currency = 'IDR'): array
    {
        $end = $endMonth->endOfMonth();
        $start = $endMonth->startOfMonth()->subMonthsNoOverflow($months - 1);

        // Grouped by day (portable across MySQL/SQLite), bucketed into months below.
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
            ->where('jl.user_id', $user->id)
            ->where('je.user_id', $user->id)
            ->where('je.currency_code', $currency)
            ->whereIn('la.class', [LedgerAccountClass::INCOME->value, LedgerAccountClass::EXPENSE->value])
            ->whereBetween('je.entry_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('je.entry_date', 'la.class')
            ->select('je.entry_date', 'la.class')
            ->selectRaw("SUM(CASE WHEN jl.direction = 'DEBIT' THEN jl.amount ELSE 0 END) AS debit")
            ->selectRaw("SUM(CASE WHEN jl.direction = 'CREDIT' THEN jl.amount ELSE 0 END) AS credit")
            ->get();

        $buckets = [];
        for ($m = $start; $m <= $end; $m = $m->addMonthNoOverflow()) {
            $buckets[$m->format('Y-m')] = ['month' => $m, 'income' => Money::zero($currency), 'expense' => Money::zero($currency)];
        }

        foreach ($rows as $row) {
            $key = substr((string) $row->entry_date, 0, 7);
            if (! isset($buckets[$key])) {
                continue;
            }
            $debit = Money::fromDatabase($row->debit, $currency);
            $credit = Money::fromDatabase($row->credit, $currency);

            if ($row->class === LedgerAccountClass::INCOME->value) {
                $buckets[$key]['income'] = $buckets[$key]['income']->plus($credit->minus($debit));
            } else {
                $buckets[$key]['expense'] = $buckets[$key]['expense']->plus($debit->minus($credit));
            }
        }

        return array_values(array_map(
            static fn (array $b): array => [...$b, 'net' => $b['income']->minus($b['expense'])],
            $buckets,
        ));
    }
}
