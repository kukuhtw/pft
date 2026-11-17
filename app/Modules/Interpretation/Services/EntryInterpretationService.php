<?php

declare(strict_types=1);

namespace App\Modules\Interpretation\Services;

use App\Models\User;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Counterparties\Services\CounterpartyService;
use App\Modules\Debts\Services\DebtService;
use App\Modules\Interpretation\Contracts\TextInterpreter;
use App\Modules\Interpretation\Enums\TransactionIntent;

/**
 * Free text → a confirmable proposal, enriched with the user's own data (known counterparty, their open
 * debts, category id). Read-only: nothing is created, posted, stored or logged — the text is not persisted.
 */
final class EntryInterpretationService
{
    public function __construct(
        private readonly TextInterpreter $interpreter,
        private readonly CounterpartyService $counterparties,
        private readonly DebtService $debts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function interpret(User $user, string $text, string $currency): array
    {
        $result = $this->interpreter->interpret($text, $currency);
        $known = $this->counterparties->match($user, $result->counterparty);
        $intent = $result->intent;

        $debtAccounts = $known !== null && in_array($intent, [TransactionIntent::LOAN_RECEIVED, TransactionIntent::DEBT_REPAYMENT], true)
            ? array_map(static fn (FinancialAccount $a): array => ['id' => $a->id, 'name' => $a->name, 'balance' => $a->balance()], $this->debts->debtAccountsOf($user, $known))
            : [];

        $category = $result->categoryCode !== null && ($intent?->isIncome() || $intent === TransactionIntent::EXPENSE)
            ? TransactionCategory::query()->where('user_id', $user->id)->where('code', $result->categoryCode)->where('is_active', true)->first()
            : null;

        $warnings = $result->warnings;
        if ($intent === TransactionIntent::DEBT_REPAYMENT && $result->counterparty !== null && $debtAccounts === []) {
            $warnings[] = 'NO_OPEN_DEBT_FOR_COUNTERPARTY';
        }
        if ($intent === TransactionIntent::DEBT_REPAYMENT && count($debtAccounts) > 1) {
            $warnings[] = 'CHOOSE_DEBT_ACCOUNT';
        }

        $body = array_filter([
            'amount' => $result->amount?->amount,
            'counterparty' => $known->name ?? $result->counterparty,
            'debt_account_id' => count($debtAccounts) === 1 ? $debtAccounts[0]['id'] : null,
            'category_id' => $category?->id,
            'activity' => $result->activity,
            'description' => mb_substr($text, 0, 255),
        ], static fn ($v): bool => $v !== null);

        return [
            ...$result->toArray(),
            'warnings' => array_values(array_unique($warnings)),
            'counterparty' => $result->counterparty === null ? null : [
                'name' => $known->name ?? $result->counterparty,
                'id' => $known?->id,
                'known' => $known !== null,
                'debt_accounts' => $debtAccounts,
            ],
            'category' => $category === null ? null : ['id' => $category->id, 'code' => $category->code, 'name' => $category->name],
            // What the client should POST after the user confirms (and picks the missing account).
            'suggested_request' => $intent?->operation() === null ? null : [
                'method' => 'POST',
                'path' => '/api/v1/transactions/'.$intent->operation(),
                'body' => $body,
                'missing' => $this->missingFields($intent, $body),
            ],
            'requires_confirmation' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function missingFields(TransactionIntent $intent, array $body): array
    {
        $required = match ($intent) {
            TransactionIntent::SALARY_INCOME, TransactionIntent::ACTIVITY_INCOME, TransactionIntent::OTHER_INCOME, TransactionIntent::LOAN_RECEIVED => ['amount', 'destination_account_id'],
            TransactionIntent::DEBT_REPAYMENT, TransactionIntent::EXPENSE => ['amount', 'source_account_id'],
            TransactionIntent::INTERNAL_TRANSFER => ['amount', 'source_account_id', 'destination_account_id'],
            TransactionIntent::OPENING_BALANCE => [],
        };

        if (in_array($intent, [TransactionIntent::LOAN_RECEIVED, TransactionIntent::DEBT_REPAYMENT], true)
            && ! isset($body['debt_account_id']) && ! isset($body['counterparty'])) {
            $required[] = 'counterparty';
        }

        return array_values(array_filter($required, static fn (string $f): bool => ! isset($body[$f])));
    }
}
