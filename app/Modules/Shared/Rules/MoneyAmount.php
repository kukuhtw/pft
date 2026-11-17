<?php

declare(strict_types=1);

namespace App\Modules\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts a positive amount as a JSON integer or a decimal string with ≤ 2 fraction digits.
 * JSON floats are rejected because they cannot represent money exactly.
 */
final class MoneyAmount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_float($value)) {
            $fail('The :attribute must be sent as an integer or a decimal string (e.g. "35000.50"), not a floating-point number.');

            return;
        }

        $string = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : null);

        if ($string === null || preg_match('/^\d{1,18}(\.\d{1,2})?$/', $string) !== 1) {
            $fail('The :attribute must be a positive amount with at most 2 decimal places.');

            return;
        }

        if (bccomp($string, '0', 2) !== 1) {
            $fail('The :attribute must be greater than zero.');
        }
    }
}
