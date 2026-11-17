<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Http\Resources;

use App\Modules\Shared\Domain\Money;
use App\Modules\Transactions\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
final class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'transaction_date' => $this->transaction_date->toDateString(),
            'amount' => Money::fromDatabase($this->getRawOriginal('amount'), $this->currency_code),
            'source_account' => $this->whenLoaded('sourceAccount', fn () => $this->sourceAccount === null ? null : [
                'id' => $this->sourceAccount->id,
                'name' => $this->sourceAccount->name,
            ]),
            'destination_account' => $this->whenLoaded('destinationAccount', fn () => $this->destinationAccount === null ? null : [
                'id' => $this->destinationAccount->id,
                'name' => $this->destinationAccount->name,
            ]),
            'category' => $this->whenLoaded('category', fn () => $this->category === null ? null : [
                'id' => $this->category->id,
                'code' => $this->category->code,
                'name' => $this->category->name,
            ]),
            'merchant' => $this->whenLoaded('merchant', fn () => $this->merchant?->name),
            'description' => $this->description,
            'reference_number' => $this->reference_number,
            'payment_method' => $this->payment_method?->value,
            'channel' => $this->channel->value,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversal_reason' => $this->reversal_reason,
            'journal_entries' => $this->whenLoaded('journalEntries', fn () => $this->journalEntries->map(static fn ($entry): array => [
                'id' => $entry->id,
                'entry_type' => $entry->entry_type->value,
                'entry_date' => $entry->entry_date->toDateString(),
                'posted_at' => $entry->posted_at->toIso8601String(),
                'lines' => $entry->relationLoaded('lines') ? $entry->lines->map(static fn ($line): array => [
                    'ledger_account_id' => $line->ledger_account_id,
                    'direction' => $line->direction->value,
                    'amount' => $line->amount,
                ])->all() : null,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
