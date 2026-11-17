<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountStatus;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Interpretation\Enums\TransactionIntent;
use App\Modules\Ledger\Enums\LedgerAccountClass;
use App\Modules\Shared\Domain\Money;
use App\Modules\Transactions\Enums\TransactionStatus;
use App\Modules\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Read models for the web dashboard. All queries are scoped to one user and to POSTED entries.
 */
final class DashboardService
{
    /** Money in, split by meaning. LOAN_RECEIVED is shown next to income but is never part of it. */
    public const array INFLOW_INTENTS = [
        TransactionIntent::SALARY_INCOME,
        TransactionIntent::ACTIVITY_INCOME,
        TransactionIntent::OTHER_INCOME,
        TransactionIntent::LOAN_RECEIVED,
    ];

    /**
     * @return array<string, Money> intent value => total (every inflow intent present, zero when none)
     */
    public function inflowsByIntent(User $user, CarbonImmutable $from, CarbonImmutable $to, string $currency = 'IDR'): array
    {
        $rows = Transaction::query()
            ->where('user_id', $user->id)
            ->where('status', TransactionStatus::POSTED)
            ->where('currency_code', $currency)
            ->whereIn('intent', array_column(self::INFLOW_INTENTS, 'value'))
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('intent')
            ->selectRaw('intent, SUM(amount) AS total')
            ->pluck('total', 'intent');

        $result = [];
        foreach (self::INFLOW_INTENTS as $intent) {
            $result[$intent->value] = Money::fromDatabase($rows[$intent->value] ?? null, $currency);
        }

        return $result;
    }

    /**
     * Open debts grouped by the party they are owed to (accounts without a party are grouped by their own name).
     *
     * @return list<array{party: string, counterparty_id: string|null, total: Money, accounts: list<array{id: string, name: string, type: string, balance: Money}>}>
     */
    public function debtsByCounterparty(User $user, string $currency = 'IDR'): array
    {
        $accounts = FinancialAccount::query()
            ->with(['ledgerAccount', 'counterparty', 'institution'])
            ->where('user_id', $user->id)
            ->where('status', AccountStatus::ACTIVE)
            ->where('currency_code', $currency)
            ->whereHas('ledgerAccount', static fn ($q) => $q->where('class', LedgerAccountClass::LIABILITY->value))
            ->orderBy('name')
            ->get()
            ->filter(static fn (FinancialAccount $a): bool => $a->balance()->isPositive());

        $groups = [];
        foreach ($accounts as $account) {
            $party = $account->counterparty->name ?? $account->institution->name ?? $account->name;
            $key = $account->counterparty_id ?? 'account:'.$account->id;

            $groups[$key] ??= ['party' => $party, 'counterparty_id' => $account->counterparty_id, 'total' => Money::zero($currency), 'accounts' => []];
            $groups[$key]['total'] = $groups[$key]['total']->plus($account->balance());
            $groups[$key]['accounts'][] = ['id' => $account->id, 'name' => $account->name, 'type' => $account->account_type_code, 'balance' => $account->balance()];
        }

        usort($groups, static fn (array $a, array $b): int => $b['total']->compare($a['total']));

        return array_values($groups);
    }
}
