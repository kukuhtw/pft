<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Interpretation\Enums\TransactionIntent;
use App\Modules\Interpretation\Services\IntentPostingService;
use App\Modules\Shared\Domain\Money;
use App\Modules\Transactions\Data\TransactionInput;
use App\Modules\Transactions\Enums\Channel;
use App\Modules\Transactions\Enums\TransactionStatus;
use App\Modules\Transactions\Exceptions\InvalidTransactionException;
use App\Modules\Transactions\Exceptions\TransactionNotFoundException;
use App\Modules\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Edit" a posted transaction without breaking the immutable ledger (ISO/IEC 27001 A.8.15):
 * in ONE database transaction the original is reversed (mirror journal, kept in history) and the
 * corrected version is posted through the normal rules (overdraft check, category kind, ownership).
 * If anything fails, nothing changes. The posted original is never written to (the model forbids it):
 * it is reversed with reason "Diubah", and the new version points back to it via metadata.amends.
 */
final readonly class TransactionAmendmentService
{
    /** Reversal reason that marks the old version of an edited transaction. */
    public const string REVERSAL_REASON = 'Diubah';

    /** Intents whose fields map 1:1 onto a new posting. Loans/debts: cancel and record again. */
    public const array EDITABLE = [
        TransactionIntent::SALARY_INCOME,
        TransactionIntent::ACTIVITY_INCOME,
        TransactionIntent::OTHER_INCOME,
        TransactionIntent::EXPENSE,
        TransactionIntent::INTERNAL_TRANSFER,
    ];

    public function __construct(
        private TransactionService $transactions,
        private IntentPostingService $posting,
        private AuditLogger $audit,
    ) {}

    public static function editable(Transaction $transaction): bool
    {
        return $transaction->status === TransactionStatus::POSTED
            && in_array($transaction->intent, self::EDITABLE, true);
    }

    public function amend(
        User $user,
        string $transactionId,
        Money $amount,
        CarbonImmutable $date,
        ?string $description,
        ?string $categoryId,
        ?string $sourceAccountId,
        ?string $destinationAccountId,
    ): Transaction {
        return DB::transaction(function () use ($user, $transactionId, $amount, $date, $description, $categoryId, $sourceAccountId, $destinationAccountId): Transaction {
            /** @var Transaction|null $original */
            $original = Transaction::query()->with(['counterparty', 'merchant'])
                ->where('user_id', $user->id)->whereKey($transactionId)->lockForUpdate()->first();

            if ($original === null) {
                throw new TransactionNotFoundException(__('ledger.transaction_not_found'));
            }
            if (! self::editable($original)) {
                throw new InvalidTransactionException(__('ledger.not_editable'));
            }

            $intent = $original->intent;
            $before = $this->snapshot($original);

            foreach (['source' => $sourceAccountId, 'destination' => $destinationAccountId] as $side => $id) {
                $needed = $side === 'source' ? IntentPostingService::needsSource($intent) : IntentPostingService::needsDestination($intent);
                if ($needed && ! $this->posting->accountsFor($user, $intent, $side)->contains('id', $id)) {
                    throw new InvalidTransactionException(__('ledger.account_not_found'));
                }
            }

            $this->transactions->reverse($user, $original->id, self::REVERSAL_REASON);

            $replacement = $this->posting->post($user, $intent, new TransactionInput(
                amount: $amount,
                transactionDate: $date,
                sourceAccountId: IntentPostingService::needsSource($intent) ? $sourceAccountId : null,
                destinationAccountId: IntentPostingService::needsDestination($intent) ? $destinationAccountId : null,
                categoryId: IntentPostingService::needsCategory($intent) ? $categoryId : null,
                merchantName: $original->merchant?->name,
                description: $description,
                referenceNumber: $original->reference_number,
                paymentMethod: $original->payment_method,
                channel: Channel::WEB,
                metadata: ['amends' => $original->id],
                counterpartyName: $intent === TransactionIntent::INTERNAL_TRANSFER ? null : $original->counterparty?->name,
                activity: $original->activity,
                purpose: $original->purpose,
            ));

            if ($replacement->intent !== $intent) {
                // The rules re-read the meaning from the new data; an edit must never silently change it.
                throw new InvalidTransactionException(__('ledger.not_editable'));
            }

            $after = $this->snapshot($replacement);
            $this->audit->record('transaction.amended', $user, $replacement, [
                'original_id' => $original->id,
                'changed' => array_keys(array_diff_assoc($after, $before)),
            ]);

            return $replacement;
        });
    }

    /** @return array<string, string|null> non-personal fields used to list what changed */
    private function snapshot(Transaction $t): array
    {
        return [
            'amount' => (string) $t->getRawOriginal('amount'),
            'date' => $t->transaction_date->toDateString(),
            'description' => $t->description,
            'category_id' => $t->category_id,
            'source_account_id' => $t->source_account_id,
            'destination_account_id' => $t->destination_account_id,
        ];
    }
}
