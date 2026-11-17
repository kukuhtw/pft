<?php

declare(strict_types=1);

namespace App\Modules\Shared\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Calendar date (no time, no timezone conversion). Always persisted as 'Y-m-d' so range
 * filters behave identically on MySQL DATE columns and on SQLite text columns.
 *
 * @implements CastsAttributes<CarbonImmutable, DateTimeInterface|string>
 */
final class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', substr((string) $value, 0, 10));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }
}
