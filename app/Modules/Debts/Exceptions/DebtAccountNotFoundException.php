<?php

declare(strict_types=1);

namespace App\Modules\Debts\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** A debt payment named a party the user owes nothing to. */
final class DebtAccountNotFoundException extends DomainException
{
    public function errorCode(): string
    {
        return 'DEBT_ACCOUNT_NOT_FOUND';
    }
}
