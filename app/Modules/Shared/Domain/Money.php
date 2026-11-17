<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable monetary amount backed by arbitrary-precision decimal strings (bcmath).
 * Floats are never accepted: money must arrive as int or numeric string.
 */
final readonly class Money implements JsonSerializable
{
    public const int SCALE = 2;

    private function __construct(
        public string $amount,
        public string $currency,
    ) {}

    /**
     * @throws InvalidArgumentException when the value is not an exact decimal with at most 2 fraction digits
     */
    public static function of(int|string $amount, string $currency = 'IDR'): self
    {
        $value = is_int($amount) ? (string) $amount : trim($amount);

        if (preg_match('/^-?\d{1,18}(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid money amount [{$value}].");
        }

        $normalized = bcadd($value, '0', self::SCALE);

        // Refuse silent truncation (e.g. "10.005").
        if (bccomp($normalized, $value, 18) !== 0) {
            throw new InvalidArgumentException("Money amount [{$value}] has more than ".self::SCALE.' decimal places.');
        }

        return new self($normalized, self::normalizeCurrency($currency));
    }

    /**
     * Build from a raw database value. DECIMAL columns come back as strings on MySQL;
     * some drivers (SQLite) may return int/float — floats are formatted exactly to scale.
     */
    public static function fromDatabase(int|float|string|null $amount, string $currency = 'IDR'): self
    {
        if ($amount === null) {
            return self::zero($currency);
        }

        if (is_float($amount)) {
            $amount = number_format($amount, self::SCALE, '.', '');
        }

        return new self(bcadd((string) $amount, '0', self::SCALE), self::normalizeCurrency($currency));
    }

    public static function zero(string $currency = 'IDR'): self
    {
        return new self('0.00', self::normalizeCurrency($currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(bcadd($this->amount, $other->amount, self::SCALE), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(bcsub($this->amount, $other->amount, self::SCALE), $this->currency);
    }

    public function negate(): self
    {
        return new self(bcmul($this->amount, '-1', self::SCALE), $this->currency);
    }

    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, self::SCALE);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->compare($other) === 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === 1;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === -1;
    }

    /**
     * Indonesian display format, e.g. "Rp10.000.000" or "-Rp45.000,50".
     */
    public function format(): string
    {
        $negative = $this->isNegative();
        $abs = ltrim($this->amount, '-');
        [$int, $frac] = explode('.', $abs);
        $grouped = strrev(implode('.', str_split(strrev($int), 3)));
        $symbol = $this->currency === 'IDR' ? 'Rp' : $this->currency.' ';
        $text = $symbol.$grouped.($frac !== '00' ? ','.$frac : '');

        return ($negative ? '-' : '').$text;
    }

    public function __toString(): string
    {
        return $this->amount;
    }

    /**
     * @return array{amount: string, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency, 'formatted' => $this->format()];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}.");
        }
    }

    private static function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Invalid currency code [{$currency}].");
        }

        return $currency;
    }
}
