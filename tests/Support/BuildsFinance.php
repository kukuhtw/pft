<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Accounts\Services\FinancialAccountService;
use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Identity\Services\UserProvisioningService;
use App\Modules\Ledger\Services\LedgerIntegrityVerifier;
use App\Modules\Reporting\Data\FinancialSummary;
use App\Modules\Reporting\Services\FinancialSummaryService;
use App\Modules\Shared\Domain\Money;
use App\Modules\Transactions\Data\TransactionInput;
use App\Modules\Transactions\Enums\PaymentMethod;
use App\Modules\Transactions\Models\Transaction;
use App\Modules\Transactions\Services\TransactionService;
use Carbon\CarbonImmutable;

/**
 * Test helpers that drive the real services (no direct balance manipulation).
 */
trait BuildsFinance
{
    protected function provisionedUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        app(UserProvisioningService::class)->provision($user);

        return $user;
    }

    protected function openAccount(User $user, string $name, string $type = 'BANK', ?string $institution = null, int|string $opening = 0, bool $allowNegative = false): FinancialAccount
    {
        return app(FinancialAccountService::class)->open(
            user: $user,
            name: $name,
            accountTypeCode: $type,
            institutionCode: $institution,
            openingBalance: Money::of($opening),
            allowNegativeBalance: $allowNegative,
        );
    }

    protected function category(User $user, string $code): TransactionCategory
    {
        return TransactionCategory::query()->where('user_id', $user->id)->where('code', $code)->firstOrFail();
    }

    protected function income(User $user, FinancialAccount $to, int|string $amount, string $category = 'SALARY', ?string $key = null, ?string $description = null): Transaction
    {
        return app(TransactionService::class)->recordIncome($user, new TransactionInput(
            amount: Money::of($amount),
            destinationAccountId: $to->id,
            categoryId: $this->category($user, $category)->id,
            description: $description,
            idempotencyKey: $key,
        ));
    }

    protected function expense(User $user, FinancialAccount $from, int|string $amount, string $category = 'FOOD', ?string $merchant = null, ?PaymentMethod $method = null, ?string $key = null): Transaction
    {
        return app(TransactionService::class)->recordExpense($user, new TransactionInput(
            amount: Money::of($amount),
            sourceAccountId: $from->id,
            categoryId: $this->category($user, $category)->id,
            merchantName: $merchant,
            paymentMethod: $method,
            idempotencyKey: $key,
        ));
    }

    protected function transfer(User $user, FinancialAccount $from, FinancialAccount $to, int|string $amount, ?string $key = null): Transaction
    {
        return app(TransactionService::class)->recordTransfer($user, new TransactionInput(
            amount: Money::of($amount),
            sourceAccountId: $from->id,
            destinationAccountId: $to->id,
            paymentMethod: PaymentMethod::BANK_TRANSFER,
            idempotencyKey: $key,
        ));
    }

    protected function balanceOf(FinancialAccount $account): string
    {
        return $account->fresh('ledgerAccount')->balance()->amount;
    }

    protected function summary(User $user, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): FinancialSummary
    {
        $today = CarbonImmutable::now((string) config('finance.display_timezone'));

        return app(FinancialSummaryService::class)->summarize($user, $from ?? $today->subYear(), $to ?? $today);
    }

    protected function assertLedgerIntact(?User $user = null): void
    {
        $issues = app(LedgerIntegrityVerifier::class)->verify($user?->id);
        $this->assertSame([], $issues, 'Ledger integrity issues: '.json_encode($issues));
    }
}
