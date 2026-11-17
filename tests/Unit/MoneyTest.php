<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Shared\Domain\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_exact_decimal_arithmetic(): void
    {
        // 0.1 + 0.2 must be exactly 0.30 (classic float failure).
        $this->assertSame('0.30', Money::of('0.10')->plus(Money::of('0.20'))->amount);
        $this->assertSame('9955000.00', Money::of(10_000_000)->minus(Money::of(45_000))->amount);
        $this->assertSame('999999999999999999.99', Money::of('999999999999999999.98')->plus(Money::of('0.01'))->amount);
    }

    public function test_indonesian_formatting(): void
    {
        $this->assertSame('Rp10.000.000', Money::of(10_000_000)->format());
        $this->assertSame('Rp35.000', Money::of(35_000)->format());
        $this->assertSame('Rp1.000,50', Money::of('1000.50')->format());
        $this->assertSame('-Rp45.000', Money::of(-45_000)->format());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'too precise' => ['10.005'],
            'not numeric' => ['abc'],
            'scientific' => ['1e5'],
            'thousand separators' => ['10.000.000'],
            'too large' => ['1234567890123456789'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_invalid_amounts(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of($amount);
    }

    public function test_trailing_zeros_beyond_scale_are_accepted(): void
    {
        $this->assertSame('10.50', Money::of('10.500')->amount);
    }

    public function test_currency_mismatch_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(1, 'IDR')->plus(Money::of(1, 'USD'));
    }
}
