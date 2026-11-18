<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Data;

use App\Modules\Shared\Domain\Money;
use App\Modules\Transactions\Enums\Channel;
use App\Modules\Transactions\Enums\PaymentMethod;
use Carbon\CarbonImmutable;

/**
 * Channel-agnostic input for posting a transaction (used by API, web, Telegram, AI, imports).
 */
final readonly class TransactionInput
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public Money $amount,
        public ?CarbonImmutable $transactionDate = null,
        public ?string $sourceAccountId = null,
        public ?string $destinationAccountId = null,
        public ?string $categoryId = null,
        public ?string $merchantName = null,
        public ?string $description = null,
        public ?string $referenceNumber = null,
        public ?PaymentMethod $paymentMethod = null,
        public Channel $channel = Channel::API,
        public ?string $idempotencyKey = null,
        public array $metadata = [],
        /** Party the money came from / went to (employer, lender, client…). */
        public ?string $counterpartyName = null,
        /** What earned the income, e.g. "jualan kue", "proyek website". */
        public ?string $activity = null,
        /** The liability (LOAN / CREDIT_CARD account) a loan-received or debt-payment entry belongs to. */
        public ?string $debtAccountId = null,
        /** What a loan was taken for, e.g. "bayar sekolah", "modal usaha" (LOAN_RECEIVED only). */
        public ?string $purpose = null,
    ) {}

    /** Copy with resolved source/destination accounts (used once a debt account is resolved). */
    public function withAccounts(?string $sourceAccountId, ?string $destinationAccountId): self
    {
        return new self(
            amount: $this->amount,
            transactionDate: $this->transactionDate,
            sourceAccountId: $sourceAccountId,
            destinationAccountId: $destinationAccountId,
            categoryId: $this->categoryId,
            merchantName: $this->merchantName,
            description: $this->description,
            referenceNumber: $this->referenceNumber,
            paymentMethod: $this->paymentMethod,
            channel: $this->channel,
            idempotencyKey: $this->idempotencyKey,
            metadata: $this->metadata,
            counterpartyName: $this->counterpartyName,
            activity: $this->activity,
            debtAccountId: $this->debtAccountId,
            purpose: $this->purpose,
        );
    }

    /**
     * Stable representation of the request, used to detect an idempotency key reused with a different payload.
     *
     * @return array<string, mixed>
     */
    public function fingerprint(): array
    {
        return [
            'amount' => $this->amount->amount,
            'currency' => $this->amount->currency,
            'date' => $this->transactionDate?->toDateString(),
            'source' => $this->sourceAccountId,
            'destination' => $this->destinationAccountId,
            'category' => $this->categoryId,
            'merchant' => $this->merchantName,
            'description' => $this->description,
            'reference' => $this->referenceNumber,
            'payment_method' => $this->paymentMethod?->value,
            'counterparty' => $this->counterpartyName,
            'activity' => $this->activity,
            'debt_account' => $this->debtAccountId,
            'purpose' => $this->purpose,
        ];
    }
}
