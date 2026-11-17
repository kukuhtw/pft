<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Services;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountStatus;
use App\Modules\Accounts\Models\FinancialAccount;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Categories\Enums\CategoryKind;
use App\Modules\Categories\Models\TransactionCategory;
use App\Modules\Categories\Services\CategoryService;
use App\Modules\Categories\Services\MerchantService;
use App\Modules\Counterparties\Models\Counterparty;
use App\Modules\Counterparties\Services\CounterpartyService;
use App\Modules\Interpretation\Data\Interpretation;
use App\Modules\Interpretation\Services\PostingClassifier;
use App\Modules\Ledger\Data\JournalDraft;
use App\Modules\Ledger\Data\JournalLineDraft;
use App\Modules\Ledger\Enums\JournalEntryType;
use App\Modules\Ledger\Enums\LedgerAccountClass;
use App\Modules\Ledger\Models\JournalEntry;
use App\Modules\Ledger\Models\LedgerAccount;
use App\Modules\Ledger\Services\ChartOfAccountsService;
use App\Modules\Ledger\Services\LedgerPostingService;
use App\Modules\Shared\Domain\Money;
use App\Modules\Transactions\Data\TransactionInput;
use App\Modules\Transactions\Enums\Channel;
use App\Modules\Transactions\Enums\TransactionStatus;
use App\Modules\Transactions\Enums\TransactionType;
use App\Modules\Transactions\Exceptions\AccountNotUsableException;
use App\Modules\Transactions\Exceptions\InsufficientFundsException;
use App\Modules\Transactions\Exceptions\InvalidTransactionException;
use App\Modules\Transactions\Exceptions\TransactionNotFoundException;
use App\Modules\Transactions\Exceptions\TransactionNotReversibleException;
use App\Modules\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Business operations that translate user intents into balanced journal entries.
 * Every path goes through LedgerPostingService inside a single DB transaction.
 */
final class TransactionService
{
    public function __construct(
        private readonly LedgerPostingService $ledger,
        private readonly ChartOfAccountsService $chart,
        private readonly CategoryService $categories,
        private readonly MerchantService $merchants,
        private readonly IdempotencyGuard $idempotency,
        private readonly AuditLogger $audit,
        private readonly PostingClassifier $classifier,
        private readonly CounterpartyService $counterparties,
    ) {}

    /**
     * Money received from an external source.  Dr destination / Cr income category.
     * Without category_id the category is inferred from the description ("gaji…" → SALARY, "jualan…" → BUSINESS).
     */
    public function recordIncome(User $user, TransactionInput $input): Transaction
    {
        return $this->idempotency->run($user, 'transactions.income', $input->idempotencyKey, $input->fingerprint(), function () use ($user, $input): Transaction {
            $destination = $this->ownedAccount($user, $input->destinationAccountId);
            $suggested = $input->categoryId === null ? $this->classifier->suggestIncomeCategory($input->description) : null;
            $category = $this->categories->resolveForPosting($user, $input->categoryId, CategoryKind::INCOME, $suggested);
            $this->assertCurrency($input->amount, $destination);

            return $this->persistAndPost($user, TransactionType::INCOME, $input, null, $destination, $category, [
                JournalLineDraft::debit($destination->ledger_account_id, $input->amount),
                JournalLineDraft::credit($category->ledger_account_id, $input->amount),
            ], categoryFromText: $suggested !== null && $category->code === $suggested);
        });
    }

    /** Money spent on goods/services.  Dr expense category / Cr source (asset or credit card). */
    public function recordExpense(User $user, TransactionInput $input): Transaction
    {
        return $this->idempotency->run($user, 'transactions.expense', $input->idempotencyKey, $input->fingerprint(), function () use ($user, $input): Transaction {
            $source = $this->ownedAccount($user, $input->sourceAccountId);
            $category = $this->categories->resolveForPosting($user, $input->categoryId, CategoryKind::EXPENSE);
            $this->assertCurrency($input->amount, $source);

            return $this->persistAndPost($user, TransactionType::EXPENSE, $input, $source, null, $category, [
                JournalLineDraft::debit($category->ledger_account_id, $input->amount),
                JournalLineDraft::credit($source->ledger_account_id, $input->amount),
            ]);
        });
    }

    /**
     * Movement between two of the user's own accounts.  Dr destination / Cr source.
     * Never touches income/expense accounts. Type is derived from the account classes:
     * asset→asset INTERNAL_TRANSFER, asset→liability LIABILITY_PAYMENT, liability→asset LIABILITY_DRAWDOWN.
     */
    public function recordTransfer(User $user, TransactionInput $input): Transaction
    {
        return $this->idempotency->run($user, 'transactions.transfer', $input->idempotencyKey, $input->fingerprint(), fn (): Transaction => $this->postTransfer($user, $input));
    }

    /**
     * Unguarded transfer posting. Callers must already be inside an idempotency guard / DB transaction
     * (DebtService uses it after resolving the debt account).
     */
    public function postTransfer(User $user, TransactionInput $input): Transaction
    {
        $source = $this->ownedAccount($user, $input->sourceAccountId);
        $destination = $this->ownedAccount($user, $input->destinationAccountId);

        if ($source->id === $destination->id) {
            throw new InvalidTransactionException(__('ledger.transfer_same_account'));
        }

        $this->assertCurrency($input->amount, $source);
        $this->assertCurrency($input->amount, $destination);

        $type = match (true) {
            ! $source->isLiability() && $destination->isLiability() => TransactionType::LIABILITY_PAYMENT,
            $source->isLiability() && ! $destination->isLiability() => TransactionType::LIABILITY_DRAWDOWN,
            default => TransactionType::INTERNAL_TRANSFER,
        };

        return $this->persistAndPost($user, $type, $input, $source, $destination, null, [
            JournalLineDraft::debit($destination->ledger_account_id, $input->amount),
            JournalLineDraft::credit($source->ledger_account_id, $input->amount),
        ]);
    }

    /**
     * Opening balance for a newly created account, posted against Opening Balance Equity.
     * Asset: Dr account / Cr equity.  Liability: Dr equity / Cr account.
     */
    public function recordOpeningBalance(User $user, FinancialAccount $account, Money $amount, ?CarbonImmutable $date = null): Transaction
    {
        if (! $amount->isPositive()) {
            throw new InvalidTransactionException(__('ledger.amount_positive'));
        }

        return DB::transaction(function () use ($user, $account, $amount, $date): Transaction {
            $account->loadMissing('ledgerAccount');
            $this->assertCurrency($amount, $account);
            $equity = $this->chart->systemAccount($user, ChartOfAccountsService::OPENING_BALANCE_EQUITY);

            $lines = $account->isLiability()
                ? [JournalLineDraft::debit($equity->id, $amount), JournalLineDraft::credit($account->ledger_account_id, $amount)]
                : [JournalLineDraft::debit($account->ledger_account_id, $amount), JournalLineDraft::credit($equity->id, $amount)];

            $input = new TransactionInput(
                amount: $amount,
                transactionDate: $date,
                description: 'Saldo awal '.$account->name,
                channel: Channel::SYSTEM,
            );

            $isLiability = $account->isLiability();

            return $this->persistAndPost(
                $user,
                TransactionType::ADJUSTMENT,
                $input,
                $isLiability ? $account : null,
                $isLiability ? null : $account,
                null,
                $lines,
                JournalEntryType::OPENING_BALANCE,
            );
        });
    }

    /**
     * Reverse a posted transaction by posting a mirror journal entry. The original entry is never modified.
     */
    public function reverse(User $user, string $transactionId, ?string $reason = null, ?string $idempotencyKey = null): Transaction
    {
        $fingerprint = ['transaction_id' => $transactionId, 'reason' => $reason];

        return $this->idempotency->run($user, 'transactions.reverse', $idempotencyKey, $fingerprint, function () use ($user, $transactionId, $reason): Transaction {
            /** @var Transaction|null $transaction */
            $transaction = Transaction::query()
                ->where('user_id', $user->id)
                ->whereKey($transactionId)
                ->lockForUpdate()
                ->first();

            if ($transaction === null) {
                throw new TransactionNotFoundException(__('ledger.transaction_not_found'));
            }

            $transaction->load(['sourceAccount', 'destinationAccount']);

            if ($transaction->status !== TransactionStatus::POSTED) {
                throw new TransactionNotReversibleException(__('ledger.not_reversible', ['status' => $transaction->status->value]));
            }

            /** @var JournalEntry $posting */
            $posting = $transaction->journalEntries()
                ->where('entry_type', '!=', JournalEntryType::REVERSAL->value)
                ->firstOrFail();

            $reversal = $this->ledger->reverse($posting, $user->id, $reason);

            $transaction->forceFill([
                'status' => TransactionStatus::REVERSED,
                'reversed_at' => now(),
                'reversed_by' => $user->id,
                'reversal_reason' => $reason,
            ])->save();

            $this->assertNoOverdraft($transaction->sourceAccount, $transaction->destinationAccount);

            $this->audit->record('transaction.reversed', $user, $transaction, [
                'reversal_entry_id' => $reversal->id,
                'reason' => $reason,
            ]);

            return $transaction->load(['sourceAccount', 'destinationAccount', 'category', 'merchant']);
        });
    }

    /**
     * @param  list<JournalLineDraft>  $lines
     */
    private function persistAndPost(
        User $user,
        TransactionType $type,
        TransactionInput $input,
        ?FinancialAccount $source,
        ?FinancialAccount $destination,
        ?TransactionCategory $category,
        array $lines,
        JournalEntryType $entryType = JournalEntryType::STANDARD,
        bool $categoryFromText = false,
    ): Transaction {
        if (! $input->amount->isPositive()) {
            throw new InvalidTransactionException(__('ledger.amount_positive'));
        }

        $date = $this->resolveDate($input->transactionDate);

        return DB::transaction(function () use ($user, $type, $input, $source, $destination, $category, $lines, $entryType, $date, $categoryFromText): Transaction {
            $merchant = $this->merchants->resolve($user, $input->merchantName);
            $meaning = $this->classifier->classify($type, $input, $source, $destination, $category, $categoryFromText);
            $counterparty = $this->resolveCounterparty($user, $input, $meaning, $source, $destination);

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'type' => $type,
                'intent' => $meaning->intent,
                'status' => TransactionStatus::POSTED,
                'transaction_date' => $date->toDateString(),
                'amount' => $input->amount->amount,
                'currency_code' => $input->amount->currency,
                'source_account_id' => $source?->id,
                'destination_account_id' => $destination?->id,
                'category_id' => $category?->id,
                'merchant_id' => $merchant?->id,
                'counterparty_id' => $counterparty?->id,
                'activity' => $meaning->activity,
                'description' => $input->description,
                'reference_number' => $input->referenceNumber,
                'payment_method' => $input->paymentMethod,
                'channel' => $input->channel,
                'metadata' => [...$input->metadata, 'interpretation' => [
                    'confidence' => $meaning->confidence->value,
                    'signals' => $meaning->signals,
                    'warnings' => $meaning->warnings,
                    // A name read from the text that matched no known counterparty: kept as a hint, not linked.
                    'counterparty_guess' => $counterparty === null ? $meaning->counterparty : null,
                    'source' => $meaning->source,
                ]],
                'created_by' => $user->id,
                'posted_at' => now(),
            ]);

            $entry = $this->ledger->post(new JournalDraft(
                userId: $user->id,
                postedBy: $user->id,
                entryDate: $date,
                currency: $input->amount->currency,
                lines: $lines,
                entryType: $entryType,
                transactionId: $transaction->id,
                description: $input->description,
            ));

            $this->assertNoOverdraft($source, $destination);

            $this->audit->record('transaction.posted', $user, $transaction, [
                'type' => $type->value,
                'intent' => $meaning->intent?->value,
                'counterparty_id' => $counterparty?->id,
                'amount' => $input->amount->amount,
                'currency' => $input->amount->currency,
                'journal_entry_id' => $entry->id,
                'channel' => $input->channel->value,
            ]);

            return $transaction->load(['sourceAccount', 'destinationAccount', 'category', 'merchant', 'counterparty']);
        });
    }

    /**
     * Link the entry to a counterparty:
     *   explicit name from the client → find or create;
     *   liability account linked to a party → that party;
     *   name read from the description → link only if it matches an existing counterparty (guesses never create rows).
     */
    private function resolveCounterparty(User $user, TransactionInput $input, Interpretation $meaning, ?FinancialAccount $source, ?FinancialAccount $destination): ?Counterparty
    {
        if ($input->counterpartyName !== null && trim($input->counterpartyName) !== '') {
            return $this->counterparties->resolve($user, $input->counterpartyName);
        }

        foreach ([$source, $destination] as $account) {
            if ($account !== null && $account->counterparty_id !== null && $account->isLiability()) {
                return $account->loadMissing('counterparty')->counterparty;
            }
        }

        return $this->counterparties->match($user, $meaning->counterparty);
    }

    /**
     * Tenant-scoped account lookup: another user's account ID behaves exactly like a missing one.
     */
    private function ownedAccount(User $user, ?string $accountId): FinancialAccount
    {
        $account = $accountId === null ? null : FinancialAccount::query()
            ->with('ledgerAccount')
            ->where('user_id', $user->id)
            ->whereKey($accountId)
            ->first();

        if ($account === null || $account->status !== AccountStatus::ACTIVE) {
            throw new AccountNotUsableException(__('ledger.account_not_found'));
        }

        return $account;
    }

    private function assertCurrency(Money $amount, FinancialAccount $account): void
    {
        if ($amount->currency !== $account->currency_code) {
            throw new InvalidTransactionException(__('ledger.currency_mismatch_account', [
                'currency' => $amount->currency,
                'account' => $account->name,
            ]));
        }
    }

    /**
     * Must run inside the posting DB transaction: ledger rows are already locked by the posting service,
     * so the balance read here is the committed-to-be value and cannot race.
     */
    private function assertNoOverdraft(?FinancialAccount ...$accounts): void
    {
        foreach ($accounts as $account) {
            if ($account === null || $account->allow_negative_balance) {
                continue;
            }

            /** @var LedgerAccount $ledger */
            $ledger = LedgerAccount::query()->whereKey($account->ledger_account_id)->firstOrFail();

            if ($ledger->class === LedgerAccountClass::ASSET && $ledger->balanceMoney()->isNegative()) {
                throw new InsufficientFundsException(__('ledger.insufficient_funds', ['account' => $account->name]), [
                    'account_id' => $account->id,
                ]);
            }
        }
    }

    private function resolveDate(?CarbonImmutable $date): CarbonImmutable
    {
        $today = CarbonImmutable::now((string) config('finance.display_timezone'))->startOfDay();
        $date = $date?->startOfDay() ?? $today;

        if ($date->toDateString() > $today->toDateString()) {
            throw new InvalidTransactionException(__('ledger.future_date'));
        }

        return $date;
    }
}
