<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

final class InsufficientFundsException extends DomainException
{
    public function errorCode(): string
    {
        return 'INSUFFICIENT_FUNDS';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
