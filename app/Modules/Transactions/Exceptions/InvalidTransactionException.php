<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

final class InvalidTransactionException extends DomainException
{
    public function errorCode(): string
    {
        return 'TRANSACTION_INVALID';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
