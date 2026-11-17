<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

enum EntryDirection: string
{
    case DEBIT = 'DEBIT';
    case CREDIT = 'CREDIT';

    public function opposite(): self
    {
        return $this === self::DEBIT ? self::CREDIT : self::DEBIT;
    }
}
