<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

/**
 * Raised when a journal references a ledger account that is missing, archived,
 * owned by another tenant, or denominated in another currency.
 * The message is deliberately generic so it never reveals other tenants' data.
 */
final class LedgerAccountNotUsableException extends DomainException
{
    public function errorCode(): string
    {
        return 'LEDGER_ACCOUNT_NOT_USABLE';
    }
}
