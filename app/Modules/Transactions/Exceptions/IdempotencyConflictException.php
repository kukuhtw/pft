<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

final class IdempotencyConflictException extends DomainException
{
    public function errorCode(): string
    {
        return 'IDEMPOTENCY_CONFLICT';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
