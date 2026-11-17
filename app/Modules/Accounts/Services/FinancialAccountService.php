<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountStatus;
use App\Modules\Accounts\Models\AccountType;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Accounts\Models\FinancialInstitution;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Counterparties\Models\Counterparty;
use App\Modules\Ledger\Enums\LedgerAccountClass;
use App\Modules\Ledger\Services\ChartOfAccountsService;
use App\Modules\Shared\Domain\Money;
use App\Modules\Transactions\Exceptions\InvalidTransactionException;
use App\Modules\Transactions\Models\Transaction;
use App\Modules\Transactions\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FinancialAccountService
{
    public function __construct(
        private readonly ChartOfAccountsService $chart,
        private readonly TransactionService $transactions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Open a financial account backed by a new ledger account. A non-zero opening balance is posted
     * through the ledger (against Opening Balance Equity); it is never written directly.
     */
    public function open(
        User $user,
        string $name,
        string $accountTypeCode,
        ?string $institutionCode = null,
        ?Money $openingBalance = null,
        ?CarbonImmutable $openingBalanceDate = null,
        ?string $accountNumberLast4 = null,
        ?bool $allowNegativeBalance = null,
        ?string $aliases = null,
        ?string $counterpartyId = null,
    ): FinancialAccount {
        $currency = $openingBalance->currency ?? (string) config('finance.default_currency');

        /** @var AccountType|null $type */
        $type = AccountType::query()->where('code', $accountTypeCode)->where('is_active', true)->first();
        if ($type === null) {
            throw new InvalidTransactionException(__('ledger.account_type_invalid', ['type' => $accountTypeCode]));
        }

        $institution = $institutionCode !== null
            ? FinancialInstitution::query()->where('code', strtoupper($institutionCode))->where('is_active', true)->first()
            : null;

        if ($institutionCode !== null && $institution === null) {
            throw new InvalidTransactionException(__('ledger.institution_invalid', ['code' => $institutionCode]));
        }

        if ($openingBalance !== null && $openingBalance->isNegative()) {
            throw new InvalidTransactionException(__('ledger.opening_balance_negative'));
        }

        // Only liabilities (loans, cards) are "owed to" a party.
        if ($counterpartyId !== null && $type->ledger_class !== LedgerAccountClass::LIABILITY) {
            throw new InvalidTransactionException(__('ledger.counterparty_requires_liability'));
        }

        return DB::transaction(function () use ($user, $name, $type, $institution, $currency, $openingBalance, $openingBalanceDate, $accountNumberLast4, $allowNegativeBalance, $aliases, $counterpartyId): FinancialAccount {
            $ledger = $this->chart->createAccount($user, $type->ledger_class, $name, $currency);

            $account = FinancialAccount::create([
                'user_id' => $user->id,
                'ledger_account_id' => $ledger->id,
                'account_type_code' => $type->code,
                'financial_institution_id' => $institution?->id,
                'counterparty_id' => $counterpartyId,
                'name' => $name,
                'currency_code' => $currency,
                'account_number_last4' => $accountNumberLast4,
                'opening_balance' => $openingBalance->amount ?? '0.00',
                'opening_balance_date' => $openingBalanceDate?->toDateString(),
                // Credit cards and loans are liabilities; the overdraft guard only applies to assets anyway.
                'allow_negative_balance' => $allowNegativeBalance ?? false,
                'status' => AccountStatus::ACTIVE,
                'aliases' => $aliases,
            ]);

            $this->audit->record('account.opened', $user, $account, [
                'type' => $type->code,
                'institution' => $institution?->code,
                'currency' => $currency,
            ]);

            if ($openingBalance !== null && $openingBalance->isPositive()) {
                $this->transactions->recordOpeningBalance($user, $account, $openingBalance, $openingBalanceDate);
            }

            return $account->load(['ledgerAccount', 'accountType', 'institution', 'counterparty']);
        });
    }

    /**
     * Edit an account's descriptive details: name (also on its ledger account), last 4 digits, bank/e-wallet
     * and — for liabilities only — the party it is owed to. Balances and history are untouched.
     */
    public function updateDetails(
        User $user,
        FinancialAccount $account,
        string $name,
        ?string $accountNumberLast4,
        ?string $institutionCode,
        ?string $counterpartyId,
    ): FinancialAccount {
        $this->assertOwner($user, $account);
        $name = trim($name);
        if ($name === '') {
            throw new InvalidTransactionException(__('ledger.account_name_required'));
        }

        $institution = $institutionCode !== null
            ? FinancialInstitution::query()->where('code', strtoupper($institutionCode))->where('is_active', true)->first()
            : null;
        if ($institutionCode !== null && $institution === null) {
            throw new InvalidTransactionException(__('ledger.institution_invalid', ['code' => $institutionCode]));
        }

        return DB::transaction(function () use ($user, $account, $name, $accountNumberLast4, $institution, $counterpartyId): FinancialAccount {
            $account->loadMissing('ledgerAccount');

            if ($counterpartyId !== null) {
                if (! $account->isLiability()) {
                    throw new InvalidTransactionException(__('ledger.counterparty_requires_liability'));
                }
                if (! Counterparty::query()->where('user_id', $user->id)->whereKey($counterpartyId)->exists()) {
                    throw new InvalidTransactionException(__('ledger.account_not_found'));
                }
            }

            $changes = [];
            if ($account->name !== $name) {
                // Old/new names are user-chosen labels, not personal data; logged for the audit trail.
                $changes['name'] = ['from' => $account->name, 'to' => $name];
            }
            if ($account->account_number_last4 !== $accountNumberLast4) {
                $changes['last4_changed'] = true;
            }
            if ($account->financial_institution_id !== $institution?->id) {
                $changes['institution'] = $institution?->code;
            }
            if ($account->counterparty_id !== $counterpartyId) {
                // Id only: the party's name stays out of the audit log.
                $changes['counterparty_id'] = $counterpartyId;
            }

            $account->update([
                'name' => $name,
                'account_number_last4' => $accountNumberLast4,
                'financial_institution_id' => $institution?->id,
                'counterparty_id' => $counterpartyId,
            ]);
            $account->ledgerAccount->update(['name' => $name]);

            if ($changes !== []) {
                $this->audit->record('account.updated', $user, $account, $changes);
            }

            return $account;
        });
    }

    /**
     * Fix a wrong opening balance: posts the difference against Opening Balance Equity on the opening
     * date, so history is corrected and no income/expense is created. Returns null when nothing changed.
     */
    public function correctOpeningBalance(User $user, FinancialAccount $account, Money $target): ?Transaction
    {
        $this->assertOwner($user, $account);
        $this->assertNotNegative($target);

        return DB::transaction(function () use ($user, $account, $target): ?Transaction {
            $account = FinancialAccount::query()->lockForUpdate()->findOrFail($account->id);
            $current = Money::of((string) $account->opening_balance, $account->currency_code);
            $delta = $target->minus($current);
            if ($delta->isZero()) {
                return null;
            }

            $tz = (string) config('finance.display_timezone');
            $date = $account->opening_balance_date ?? CarbonImmutable::parse($account->created_at)->setTimezone($tz);

            $transaction = $this->transactions->postBalanceCorrection(
                $user, $account, $delta, opening: true, date: CarbonImmutable::parse($date->toDateString(), $tz),
                description: 'Koreksi saldo awal: '.$current->format().' → '.$target->format(),
            );

            $account->update(['opening_balance' => $target->amount]);
            $this->audit->record('account.opening_balance_corrected', $user, $account, [
                'from' => $current->amount, 'to' => $target->amount, 'transaction_id' => $transaction->id,
            ]);

            return $transaction;
        });
    }

    /**
     * Align the balance with reality today (e.g. after forgotten small spending): posts the difference
     * against Reconciliation Equity, dated today. Returns null when the balance already matches.
     */
    public function adjustBalanceToday(User $user, FinancialAccount $account, Money $target): ?Transaction
    {
        $this->assertOwner($user, $account);
        $this->assertNotNegative($target);

        return DB::transaction(function () use ($user, $account, $target): ?Transaction {
            $account = FinancialAccount::query()->with('ledgerAccount')->lockForUpdate()->findOrFail($account->id);
            $current = $account->balance();
            $delta = $target->minus($current);
            if ($delta->isZero()) {
                return null;
            }

            $transaction = $this->transactions->postBalanceCorrection(
                $user, $account, $delta, opening: false, date: null,
                description: 'Penyesuaian saldo: '.$current->format().' → '.$target->format(),
            );

            $this->audit->record('account.balance_adjusted', $user, $account, [
                'from' => $current->amount, 'to' => $target->amount, 'transaction_id' => $transaction->id,
            ]);

            return $transaction;
        });
    }

    private function assertOwner(User $user, FinancialAccount $account): void
    {
        if ($account->user_id !== $user->id) {
            // Same message as "not found": never confirm another user's account exists.
            throw new InvalidTransactionException(__('ledger.account_not_found'));
        }
    }

    private function assertNotNegative(Money $amount): void
    {
        if ($amount->isNegative()) {
            throw new InvalidTransactionException(__('ledger.balance_negative'));
        }
    }
}
