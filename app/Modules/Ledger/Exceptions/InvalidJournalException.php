<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

final class InvalidJournalException extends DomainException
{
    public function errorCode(): string
    {
        return 'LEDGER_INVALID_JOURNAL';
    }
}
