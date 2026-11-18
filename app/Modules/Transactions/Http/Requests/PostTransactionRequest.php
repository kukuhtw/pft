<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Http\Requests;

use App\Modules\Shared\Domain\Money;
use App\Modules\Shared\Rules\MoneyAmount;
use App\Modules\Transactions\Data\TransactionInput;
use App\Modules\Transactions\Enums\Channel;
use App\Modules\Transactions\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for income / expense / transfer endpoints.
 * Ownership of referenced accounts/categories is enforced in the service layer (tenant-scoped lookups).
 */
abstract class PostTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $today = CarbonImmutable::now((string) config('finance.display_timezone'))->toDateString();

        return [
            'amount' => ['required', new MoneyAmount],
            'currency' => ['sometimes', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true)],
            'transaction_date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'category_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'merchant' => ['sometimes', 'nullable', 'string', 'max:128'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'payment_method' => ['sometimes', 'nullable', Rule::enum(PaymentMethod::class)],
            'source_account_id' => ['string', 'ulid'],
            'destination_account_id' => ['string', 'ulid'],
            // Semantics: who the money came from / went to, and what earned it. Names only (data minimisation).
            'counterparty' => ['sometimes', 'nullable', 'string', 'max:128'],
            'activity' => ['sometimes', 'nullable', 'string', 'max:64'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }

    public function toInput(): TransactionInput
    {
        $date = $this->validated('transaction_date');
        $method = $this->validated('payment_method');

        return new TransactionInput(
            amount: Money::of($this->validated('amount'), (string) ($this->validated('currency') ?? config('finance.default_currency'))),
            transactionDate: $date !== null ? CarbonImmutable::createFromFormat('Y-m-d', $date, (string) config('finance.display_timezone')) : null,
            sourceAccountId: $this->validated('source_account_id'),
            destinationAccountId: $this->validated('destination_account_id'),
            categoryId: $this->validated('category_id'),
            merchantName: $this->validated('merchant'),
            description: $this->validated('description'),
            referenceNumber: $this->validated('reference_number'),
            paymentMethod: $method !== null ? PaymentMethod::from($method) : null,
            channel: Channel::API,
            idempotencyKey: $this->header('Idempotency-Key'),
            counterpartyName: $this->validated('counterparty'),
            activity: $this->validated('activity'),
            purpose: $this->validated('purpose'),
            debtAccountId: $this->validated('debt_account_id'),
        );
    }
}
