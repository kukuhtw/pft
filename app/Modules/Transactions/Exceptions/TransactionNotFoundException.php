<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

final class TransactionNotFoundException extends DomainException
{
    public function errorCode(): string
    {
        return 'TRANSACTION_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
