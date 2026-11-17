<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Modules\Interpretation\Services\TextAnalyzer;
use App\Modules\Shared\Domain\Money;

/**
 * Parses an amount typed by a person into a form field: "50000", "2.000.000", "1,5jt", "500rb", "Rp 750.000".
 * Returns null for anything that is not a positive exact amount.
 */
final class MoneyInput
{
    public static function parse(?string $input, string $currency = 'IDR'): ?Money
    {
        $input = trim((string) $input);

        if ($input === '') {
            return null;
        }

        if (preg_match('/^\d{1,18}(\.\d{1,2})?$/', $input) === 1) {
            $money = Money::of($input, $currency);

            return $money->isPositive() ? $money : null;
        }

        // "Rp" prefix lets the pattern engine accept short bare numbers too.
        $text = preg_match('/^rp/i', $input) === 1 ? $input : 'Rp '.$input;

        return app(TextAnalyzer::class)->amount($text, $currency);
    }
}
