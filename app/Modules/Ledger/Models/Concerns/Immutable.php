<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models\Concerns;

use App\Modules\Ledger\Exceptions\ImmutableRecordException;

/**
 * Append-only records: once inserted they can never be updated or deleted.
 * Corrections must be made with reversal entries.
 */
trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(static function ($model): never {
            throw new ImmutableRecordException(class_basename($model));
        });

        static::deleting(static function ($model): never {
            throw new ImmutableRecordException(class_basename($model));
        });
    }
}
