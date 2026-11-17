<?php

declare(strict_types=1);

namespace App\Modules\Interpretation\Services;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountStatus;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Debts\Services\DebtService;
use App\Modules\Interpretation\Enums\TransactionIntent;
use App\Modules\Transactions\Data\TransactionInput;
use App\Modules\Transactions\Models\Transaction;
use App\Modules\Transactions\Services\TransactionService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Posts a confirmed proposal by its intent. Shared by every conversational channel
 * (web "Catat transaksi", Telegram) so they all follow exactly the same ledger rules.
 */
final readonly class IntentPostingService
{
    /** Default category per intent when neither the user nor the text chose one. */
    public const array DEFAULT_CATEGORY = [
        'SALARY_INCOME' => 'SALARY',
        'ACTIVITY_INCOME' => 'BUSINESS',
        'OTHER_INCOME' => 'OTHER_INCOME',
        'EXPENSE' => 'OTHER_EXPENSE',
    ];

    public function __construct(
        private TransactionService $transactions,
        private DebtService $debts,
    ) {}

    public function post(User $user, TransactionIntent $intent, TransactionInput $input): Transaction
    {
        return match ($intent) {
            TransactionIntent::SALARY_INCOME, TransactionIntent::ACTIVITY_INCOME, TransactionIntent::OTHER_INCOME => $this->transactions->recordIncome($user, $input),
            TransactionIntent::LOAN_RECEIVED => $this->debts->loanReceived($user, $input),
            TransactionIntent::DEBT_REPAYMENT => $this->debts->debtPayment($user, $input),
            TransactionIntent::EXPENSE => $this->transactions->recordExpense($user, $input),
            TransactionIntent::INTERNAL_TRANSFER => $this->transactions->recordTransfer($user, $input),
            TransactionIntent::OPENING_BALANCE, TransactionIntent::BALANCE_ADJUSTMENT => throw new \LogicException('Balance corrections are posted from the Accounts page.'),
        };
    }

    /** @return list<TransactionIntent> */
    public static function selectableIntents(): array
    {
        return array_values(array_filter(TransactionIntent::cases(), static fn (TransactionIntent $i): bool => ! $i->isSystem()));
    }

    public static function needsSource(?TransactionIntent $i): bool
    {
        return in_array($i, [TransactionIntent::EXPENSE, TransactionIntent::DEBT_REPAYMENT, TransactionIntent::INTERNAL_TRANSFER], true);
    }

    public static function needsDestination(?TransactionIntent $i): bool
    {
        return $i !== null && ($i->isIncome() || in_array($i, [TransactionIntent::LOAN_RECEIVED, TransactionIntent::INTERNAL_TRANSFER], true));
    }

    public static function needsCategory(?TransactionIntent $i): bool
    {
        return $i === TransactionIntent::EXPENSE || $i?->isIncome() === true;
    }

    public static function needsParty(?TransactionIntent $i): bool
    {
        return in_array($i, [TransactionIntent::LOAN_RECEIVED, TransactionIntent::DEBT_REPAYMENT], true);
    }

    /**
     * Active accounts of one ledger side, owner-scoped.
     *
     * @return Collection<int, FinancialAccount>
     */
    public function accounts(User $user, bool $liability): Collection
    {
        return FinancialAccount::query()
            ->with(['ledgerAccount', 'counterparty'])
            ->where('user_id', $user->id)
            ->where('status', AccountStatus::ACTIVE)
            ->whereHas('ledgerAccount', static fn ($q) => $q->where('class', $liability ? 'LIABILITY' : 'ASSET'))
            ->orderBy('name')
            ->get();
    }
}
