<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

final class TransactionNotReversibleException extends DomainException
{
    public function errorCode(): string
    {
        return 'TRANSACTION_NOT_REVERSIBLE';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
