<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Http\Requests;

final class TransferRequest extends PostTransactionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['source_account_id'] = ['required', ...$rules['source_account_id']];
        $rules['destination_account_id'] = ['required', 'different:source_account_id', ...$rules['destination_account_id']];

        return $rules;
    }
}
