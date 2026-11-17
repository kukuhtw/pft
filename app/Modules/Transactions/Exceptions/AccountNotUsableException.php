<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

final class AccountNotUsableException extends DomainException
{
    public function errorCode(): string
    {
        return 'ACCOUNT_NOT_USABLE';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
