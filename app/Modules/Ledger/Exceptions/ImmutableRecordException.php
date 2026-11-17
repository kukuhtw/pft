<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

final class ImmutableRecordException extends DomainException
{
    public function __construct(string $model)
    {
        parent::__construct(__('ledger.immutable', ['model' => $model]), ['model' => $model]);
    }

    public function errorCode(): string
    {
        return 'LEDGER_IMMUTABLE_RECORD';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
