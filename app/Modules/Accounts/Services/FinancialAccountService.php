<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountStatus;
use App\Modules\Accounts\Models\AccountType;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Accounts\Models\FinancialInstitution;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Ledger\Services\ChartOfAccountsService;
use App\Modules\Shared\Domain\Money;
use App\Modules\Transactions\Exceptions\InvalidTransactionException;
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

        return DB::transaction(function () use ($user, $name, $type, $institution, $currency, $openingBalance, $openingBalanceDate, $accountNumberLast4, $allowNegativeBalance, $aliases): FinancialAccount {
            $ledger = $this->chart->createAccount($user, $type->ledger_class, $name, $currency);

            $account = FinancialAccount::create([
                'user_id' => $user->id,
                'ledger_account_id' => $ledger->id,
                'account_type_code' => $type->code,
                'financial_institution_id' => $institution?->id,
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

            return $account->load(['ledgerAccount', 'accountType', 'institution']);
        });
    }
}
