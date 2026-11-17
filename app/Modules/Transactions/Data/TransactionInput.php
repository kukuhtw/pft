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
    ) {}

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
        ];
    }
}
