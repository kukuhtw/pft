<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

final class UnbalancedJournalException extends DomainException
{
    public static function totals(string $debit, string $credit): self
    {
        return new self(__('ledger.unbalanced', ['debit' => $debit, 'credit' => $credit]), [
            'total_debit' => $debit,
            'total_credit' => $credit,
        ]);
    }

    public function errorCode(): string
    {
        return 'LEDGER_UNBALANCED';
    }
}
