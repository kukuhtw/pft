<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Http\Requests;

final class IncomeRequest extends PostTransactionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['destination_account_id'] = ['required', ...$rules['destination_account_id']];
        $rules['source_account_id'] = ['prohibited'];

        return $rules;
    }
}
