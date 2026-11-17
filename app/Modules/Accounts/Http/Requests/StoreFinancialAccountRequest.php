<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Requests;

use App\Modules\Shared\Rules\MoneyAmount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreFinancialAccountRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:128', Rule::unique('financial_accounts', 'name')->where('user_id', $this->user()?->id)],
            'account_type' => ['required', 'string', Rule::exists('account_types', 'code')->where('is_active', true)],
            'institution_code' => ['sometimes', 'nullable', 'string', Rule::exists('financial_institutions', 'code')->where('is_active', true)],
            'currency' => ['sometimes', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true)],
            'opening_balance' => ['sometimes', 'nullable', new MoneyAmount],
            'opening_balance_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            // Data minimisation: only the last 4 digits are accepted, never a full account/card number.
            'account_number_last4' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}$/'],
            'allow_negative_balance' => ['sometimes', 'boolean'],
            'aliases' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
