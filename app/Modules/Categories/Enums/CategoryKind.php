<?php

declare(strict_types=1);

namespace App\Modules\Categories\Enums;

use App\Modules\Ledger\Enums\LedgerAccountClass;

enum CategoryKind: string
{
    case INCOME = 'INCOME';
    case EXPENSE = 'EXPENSE';

    public function ledgerClass(): LedgerAccountClass
    {
        return $this === self::INCOME ? LedgerAccountClass::INCOME : LedgerAccountClass::EXPENSE;
    }
}
