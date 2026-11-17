<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Http\Requests;

/**
 * Money borrowed from a party lands in one of the user's asset accounts.
 * Identify the debt by debt_account_id, or by counterparty (a LOAN account is opened on first use).
 */
final class LoanReceivedRequest extends PostTransactionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['destination_account_id'] = ['required', ...$rules['destination_account_id']];
        $rules['source_account_id'] = ['prohibited'];
        $rules['category_id'] = ['prohibited'];
        $rules['debt_account_id'] = ['sometimes', 'nullable', 'string', 'ulid'];
        $rules['counterparty'] = ['required_without:debt_account_id', 'nullable', 'string', 'max:128'];

        return $rules;
    }
}
