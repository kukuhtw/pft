<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Services;

use App\Modules\Ledger\Enums\EntryDirection;
use App\Modules\Ledger\Enums\LedgerAccountClass;
use App\Modules\Shared\Domain\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only consistency checks over the whole ledger (or one tenant):
 *  1. every journal entry is balanced and its header totals match its lines;
 *  2. every entry has at least two lines;
 *  3. ledger_accounts.balance (projection) equals Σ journal lines;
 *  4. no line crosses tenants (line/entry/account owners agree);
 *  5. POSTED transactions have a posting entry; REVERSED ones also have a reversal entry.
 */
final class LedgerIntegrityVerifier
{
    /**
     * @return list<array<string, mixed>> empty when the ledger is consistent
     */
    public function verify(?int $userId = null): array
    {
        return [
            ...$this->unbalancedEntries($userId),
            ...$this->projectionMismatches($userId),
            ...$this->crossTenantLines($userId),
            ...$this->transactionsWithoutJournals($userId),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function unbalancedEntries(?int $userId): array
    {
        $debit = "SUM(CASE WHEN jl.direction = 'DEBIT' THEN jl.amount ELSE 0 END)";
        $credit = "SUM(CASE WHEN jl.direction = 'CREDIT' THEN jl.amount ELSE 0 END)";

        $rows = DB::table('journal_entries as je')
            ->leftJoin('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
            ->when($userId !== null, static fn (Builder $q) => $q->where('je.user_id', $userId))
            ->groupBy('je.id', 'je.total_debit', 'je.total_credit')
            ->select('je.id', 'je.total_debit', 'je.total_credit')
            ->selectRaw("{$debit} AS line_debit, {$credit} AS line_credit, COUNT(jl.id) AS line_count")
            ->get();

        $issues = [];
        foreach ($rows as $row) {
            $lineDebit = Money::fromDatabase($row->line_debit);
            $lineCredit = Money::fromDatabase($row->line_credit);

            if ((int) $row->line_count < 2
                || ! $lineDebit->equals($lineCredit)
                || ! $lineDebit->equals(Money::fromDatabase($row->total_debit))
                || ! $lineCredit->equals(Money::fromDatabase($row->total_credit))) {
                $issues[] = [
                    'check' => 'unbalanced_entry',
                    'journal_entry_id' => $row->id,
                    'line_count' => (int) $row->line_count,
                    'line_debit' => $lineDebit->amount,
                    'line_credit' => $lineCredit->amount,
                ];
            }
        }

        return $issues;
    }

    /** @return list<array<string, mixed>> */
    private function projectionMismatches(?int $userId): array
    {
        $rows = DB::table('ledger_accounts as la')
            ->leftJoin('journal_lines as jl', 'jl.ledger_account_id', '=', 'la.id')
            ->when($userId !== null, static fn (Builder $q) => $q->where('la.user_id', $userId))
            ->groupBy('la.id', 'la.code', 'la.class', 'la.balance')
            ->select('la.id', 'la.code', 'la.class', 'la.balance')
            ->selectRaw("COALESCE(SUM(CASE WHEN jl.direction = 'DEBIT' THEN jl.amount ELSE 0 END), 0) AS debit")
            ->selectRaw("COALESCE(SUM(CASE WHEN jl.direction = 'CREDIT' THEN jl.amount ELSE 0 END), 0) AS credit")
            ->get();

        $issues = [];
        foreach ($rows as $row) {
            $debit = Money::fromDatabase($row->debit);
            $credit = Money::fromDatabase($row->credit);
            $expected = LedgerAccountClass::from($row->class)->normalBalance() === EntryDirection::DEBIT
                ? $debit->minus($credit)
                : $credit->minus($debit);
            $actual = Money::fromDatabase($row->balance);

            if (! $expected->equals($actual)) {
                $issues[] = [
                    'check' => 'projection_mismatch',
                    'ledger_account_id' => $row->id,
                    'code' => $row->code,
                    'projection' => $actual->amount,
                    'ledger' => $expected->amount,
                ];
            }
        }

        return $issues;
    }

    /** @return list<array<string, mixed>> */
    private function crossTenantLines(?int $userId): array
    {
        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
            ->when($userId !== null, static fn (Builder $q) => $q->where('je.user_id', $userId))
            ->where(static fn (Builder $q) => $q->whereColumn('jl.user_id', '!=', 'je.user_id')->orWhereColumn('la.user_id', '!=', 'je.user_id'))
            ->select('jl.id', 'jl.journal_entry_id')
            ->get()
            ->map(static fn ($r): array => ['check' => 'cross_tenant_line', 'journal_line_id' => $r->id, 'journal_entry_id' => $r->journal_entry_id])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function transactionsWithoutJournals(?int $userId): array
    {
        $postingExists = static fn (Builder $q) => $q->selectRaw('1')->from('journal_entries as je')
            ->whereColumn('je.transaction_id', 't.id')
            ->where('je.entry_type', '!=', 'REVERSAL');

        $reversalExists = static fn (Builder $q) => $q->selectRaw('1')->from('journal_entries as je')
            ->whereColumn('je.transaction_id', 't.id')
            ->where('je.entry_type', 'REVERSAL');

        $base = static fn () => DB::table('transactions as t')
            ->when($userId !== null, static fn (Builder $q) => $q->where('t.user_id', $userId));

        $missingPosting = $base()->whereIn('t.status', ['POSTED', 'REVERSED'])->whereNotExists($postingExists)->pluck('t.id');
        $missingReversal = $base()->where('t.status', 'REVERSED')->whereNotExists($reversalExists)->pluck('t.id');

        return [
            ...$missingPosting->map(static fn ($id): array => ['check' => 'transaction_without_posting', 'transaction_id' => $id])->all(),
            ...$missingReversal->map(static fn ($id): array => ['check' => 'reversed_without_reversal_entry', 'transaction_id' => $id])->all(),
        ];
    }
}
