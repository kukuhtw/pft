<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Resources;

use App\Modules\Accounts\Models\FinancialAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FinancialAccount
 */
final class FinancialAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'account_type' => $this->account_type_code,
            'ledger_class' => $this->ledgerAccount->class->value,
            'institution' => $this->whenLoaded('institution', fn () => $this->institution === null ? null : [
                'code' => $this->institution->code,
                'name' => $this->institution->name,
            ]),
            'currency' => $this->currency_code,
            'account_number_last4' => $this->account_number_last4,
            'opening_balance' => $this->opening_balance,
            // Normal-balance sign: assets = money held, liabilities = amount owed.
            'balance' => $this->balance(),
            'allow_negative_balance' => $this->allow_negative_balance,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
