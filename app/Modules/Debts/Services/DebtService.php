<?php

declare(strict_types=1);

namespace App\Modules\Debts\Services;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountStatus;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Accounts\Services\FinancialAccountService;
use App\Modules\Counterparties\Models\Counterparty;
use App\Modules\Counterparties\Services\CounterpartyService;
use App\Modules\Debts\Exceptions\DebtAccountAmbiguousException;
use App\Modules\Debts\Exceptions\DebtAccountNotFoundException;
use App\Modules\Ledger\Enums\LedgerAccountClass;
use App\Modules\Shared\Domain\Money;
use App\Modules\Transactions\Data\TransactionInput;
use App\Modules\Transactions\Exceptions\AccountNotUsableException;
use App\Modules\Transactions\Exceptions\InvalidTransactionException;
use App\Modules\Transactions\Models\Transaction;
use App\Modules\Transactions\Services\IdempotencyGuard;
use App\Modules\Transactions\Services\TransactionService;

/**
 * Party-aware debt operations on top of the ledger:
 *   loanReceived: "pinjam 2jt dari Pak Budi masuk BCA" → Dr BCA / Cr Loan(Pak Budi)   (LIABILITY_DRAWDOWN, never income)
 *   debtPayment:  "bayar hutang 1jt ke Pak Budi dari BCA" → Dr Loan(Pak Budi) / Cr BCA (LIABILITY_PAYMENT, never expense)
 *
 * The debt is a LOAN / CREDIT_CARD account linked to the counterparty. A client may name the account
 * (debt_account_id) or only the party; for a new loan from an unknown party the LOAN account is opened here.
 */
final class DebtService
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly FinancialAccountService $accounts,
        private readonly CounterpartyService $counterparties,
        private readonly IdempotencyGuard $idempotency,
    ) {}

    public function loanReceived(User $user, TransactionInput $input): Transaction
    {
        return $this->idempotency->run($user, 'transactions.loan_received', $input->idempotencyKey, $input->fingerprint(), function () use ($user, $input): Transaction {
            $this->assertAssetAccount($user, $input->destinationAccountId);
            $debt = $this->resolveDebtAccount($user, $input, openIfMissing: true);

            return $this->transactions->postTransfer($user, $input->withAccounts($debt->id, $input->destinationAccountId));
        });
    }

    public function debtPayment(User $user, TransactionInput $input): Transaction
    {
        return $this->idempotency->run($user, 'transactions.debt_payment', $input->idempotencyKey, $input->fingerprint(), function () use ($user, $input): Transaction {
            $this->assertAssetAccount($user, $input->sourceAccountId);
            $debt = $this->resolveDebtAccount($user, $input, openIfMissing: false);

            return $this->transactions->postTransfer($user, $input->withAccounts($input->sourceAccountId, $debt->id));
        });
    }

    /**
     * Active liability accounts owed to a counterparty.
     *
     * @return list<FinancialAccount>
     */
    public function debtAccountsOf(User $user, Counterparty $counterparty): array
    {
        return FinancialAccount::query()
            ->with('ledgerAccount')
            ->where('user_id', $user->id)
            ->where('counterparty_id', $counterparty->id)
            ->where('status', AccountStatus::ACTIVE)
            ->whereHas('ledgerAccount', static fn ($q) => $q->where('class', LedgerAccountClass::LIABILITY->value))
            ->orderBy('name')
            ->get()
            ->all();
    }

    private function resolveDebtAccount(User $user, TransactionInput $input, bool $openIfMissing): FinancialAccount
    {
        if ($input->debtAccountId !== null) {
            $account = FinancialAccount::query()->with('ledgerAccount')
                ->where('user_id', $user->id)->whereKey($input->debtAccountId)->where('status', AccountStatus::ACTIVE)->first();

            if ($account === null) {
                throw new AccountNotUsableException(__('ledger.account_not_found'));
            }
            if (! $account->isLiability()) {
                throw new InvalidTransactionException(__('ledger.debt_account_not_liability', ['account' => $account->name]));
            }

            // First time the party is named for this debt: remember it so future entries resolve by name.
            if ($account->counterparty_id === null && ($party = $this->counterparties->resolve($user, $input->counterpartyName)) !== null) {
                $account->forceFill(['counterparty_id' => $party->id])->save();
            }

            return $account;
        }

        $party = $this->counterparties->resolve($user, $input->counterpartyName)
            ?? throw new InvalidTransactionException(__('ledger.debt_party_required'));

        $candidates = $this->debtAccountsOf($user, $party);

        if (count($candidates) > 1) {
            throw new DebtAccountAmbiguousException(__('ledger.debt_account_ambiguous', ['party' => $party->name]), [
                'counterparty_id' => $party->id,
                'debt_accounts' => array_map(static fn (FinancialAccount $a): array => ['id' => $a->id, 'name' => $a->name], $candidates),
            ]);
        }

        if ($candidates !== []) {
            return $candidates[0];
        }

        if (! $openIfMissing) {
            throw new DebtAccountNotFoundException(__('ledger.debt_account_not_found', ['party' => $party->name]), [
                'counterparty_id' => $party->id,
            ]);
        }

        return $this->accounts->open(
            user: $user,
            name: $this->uniqueAccountName($user, 'Pinjaman '.$party->name),
            accountTypeCode: 'LOAN',
            openingBalance: Money::zero($input->amount->currency),
            counterpartyId: $party->id,
        );
    }

    private function assertAssetAccount(User $user, ?string $accountId): void
    {
        $account = $accountId === null ? null : FinancialAccount::query()->with('ledgerAccount')
            ->where('user_id', $user->id)->whereKey($accountId)->first();

        if ($account !== null && $account->isLiability()) {
            throw new InvalidTransactionException(__('ledger.debt_cash_account_must_be_asset', ['account' => $account->name]));
        }
        // Missing / inactive accounts are rejected by TransactionService with the standard error.
    }

    private function uniqueAccountName(User $user, string $base): string
    {
        $base = mb_substr($base, 0, 120);
        $name = $base;

        for ($i = 2; FinancialAccount::query()->where('user_id', $user->id)->where('name', $name)->exists(); $i++) {
            $name = $base.' ('.$i.')';
        }

        return $name;
    }
}
