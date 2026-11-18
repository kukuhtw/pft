<?php

declare(strict_types=1);

namespace App\Modules\Debts\Services;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountStatus;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Ledger\Enums\LedgerAccountClass;
use App\Modules\Shared\Domain\Money;
use App\Modules\Transactions\Enums\TransactionStatus;
use App\Modules\Transactions\Enums\TransactionType;
use App\Modules\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Catatan hutang: for every loan the user owes — when it happened, to whom, what it was for,
 * what has been repaid and what is left. Read-only view over posted transactions; the ledger
 * stays the single source of truth (remaining = the LOAN account's ledger balance).
 *
 * Credit cards are left out on purpose: their "purpose" is the expense category of each purchase.
 */
final readonly class DebtRegisterService
{
    /**
     * @return list<array{
     *     account: FinancialAccount,
     *     party: string,
     *     counterparty_id: string|null,
     *     since: CarbonImmutable|null,
     *     borrowed: Money,
     *     repaid: Money,
     *     remaining: Money,
     *     settled: bool,
     *     purposes: list<string>,
     *     entries: list<array{id: string, date: CarbonImmutable, kind: string, increases: bool, amount: Money, purpose: string|null, description: string|null, account: string|null}>
     * }>
     */
    public function register(User $user, bool $includeSettled = true, ?string $counterpartyId = null): array
    {
        $accounts = FinancialAccount::query()
            ->with(['ledgerAccount', 'counterparty'])
            ->where('user_id', $user->id)
            ->where('account_type_code', 'LOAN')
            ->where('status', AccountStatus::ACTIVE)
            ->whereHas('ledgerAccount', static fn ($q) => $q->where('class', LedgerAccountClass::LIABILITY->value))
            ->when($counterpartyId !== null, static fn ($q) => $q->where('counterparty_id', $counterpartyId))
            ->get();

        if ($accounts->isEmpty()) {
            return [];
        }

        $ids = $accounts->pluck('id')->all();
        $transactions = Transaction::query()
            ->with(['sourceAccount', 'destinationAccount'])
            ->where('user_id', $user->id)
            ->where('status', TransactionStatus::POSTED)
            ->where(static fn ($q) => $q->whereIn('source_account_id', $ids)->orWhereIn('destination_account_id', $ids))
            ->orderBy('transaction_date')->orderBy('created_at')->orderBy('id')
            ->get();

        $rows = [];
        foreach ($accounts as $account) {
            $rows[] = $this->row($account, $transactions->filter(
                static fn (Transaction $t): bool => $t->source_account_id === $account->id || $t->destination_account_id === $account->id,
            )->values()->all());
        }

        if (! $includeSettled) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ! $r['settled']));
        }

        // Open debts first (largest remaining first), then settled ones, most recent first.
        usort($rows, static function (array $a, array $b): int {
            if ($a['settled'] !== $b['settled']) {
                return $a['settled'] <=> $b['settled'];
            }

            return $a['settled']
                ? ($b['since']?->getTimestamp() ?? 0) <=> ($a['since']?->getTimestamp() ?? 0)
                : $b['remaining']->compare($a['remaining']);
        });

        return $rows;
    }

    /**
     * @param  list<Transaction>  $transactions
     * @return array<string, mixed>
     */
    private function row(FinancialAccount $account, array $transactions): array
    {
        $currency = $account->currency_code;
        $borrowed = Money::zero($currency);
        $repaid = Money::zero($currency);
        $entries = [];
        $purposes = [];

        foreach ($transactions as $t) {
            $amount = Money::fromDatabase($t->getRawOriginal('amount'), $t->currency_code);
            // A liability on the source side grows (money left the lender); on the destination side it shrinks.
            $increases = $t->source_account_id === $account->id;

            $kind = match (true) {
                $t->type === TransactionType::LIABILITY_DRAWDOWN => 'LOAN',
                $t->type === TransactionType::LIABILITY_PAYMENT => 'PAYMENT',
                $t->type === TransactionType::ADJUSTMENT && ($t->metadata['adjustment'] ?? 'opening') === 'opening' => 'OPENING',
                default => $increases ? 'INCREASE' : 'DECREASE',
            };

            if ($increases) {
                $borrowed = $borrowed->plus($amount);
            } else {
                $repaid = $repaid->plus($amount);
            }

            if ($t->purpose !== null && ! in_array($t->purpose, $purposes, true)) {
                $purposes[] = $t->purpose;
            }

            $other = $increases ? $t->destinationAccount : $t->sourceAccount;
            $entries[] = [
                'id' => $t->id,
                'date' => CarbonImmutable::instance($t->transaction_date),
                'kind' => $kind,
                'increases' => $increases,
                'amount' => $amount,
                'purpose' => $t->purpose,
                'description' => $t->description,
                'account' => $other?->name,
            ];
        }

        $remaining = $account->balance();

        return [
            'account' => $account,
            'party' => $account->counterparty->name ?? $account->name,
            'counterparty_id' => $account->counterparty_id,
            'since' => $entries[0]['date'] ?? null,
            'borrowed' => $borrowed,
            'repaid' => $repaid,
            'remaining' => $remaining,
            'settled' => ! $remaining->isPositive(),
            'purposes' => $purposes,
            'entries' => $entries,
        ];
    }
}
