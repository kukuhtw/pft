<?php

declare(strict_types=1);

namespace App\Modules\Debts\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/** The party has several open debts; the client must pick one with debt_account_id (details list them). */
final class DebtAccountAmbiguousException extends DomainException
{
    public function errorCode(): string
    {
        return 'DEBT_ACCOUNT_AMBIGUOUS';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
