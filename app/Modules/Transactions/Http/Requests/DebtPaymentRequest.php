<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Http\Requests;

/**
 * Payment from one of the user's asset accounts to a party, for a specific debt.
 * Identify the debt by debt_account_id, or by counterparty when that party has exactly one open debt.
 */
final class DebtPaymentRequest extends PostTransactionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['source_account_id'] = ['required', ...$rules['source_account_id']];
        $rules['destination_account_id'] = ['prohibited'];
        $rules['category_id'] = ['prohibited'];
        $rules['debt_account_id'] = ['sometimes', 'nullable', 'string', 'ulid'];
        $rules['counterparty'] = ['required_without:debt_account_id', 'nullable', 'string', 'max:128'];

        return $rules;
    }
}
