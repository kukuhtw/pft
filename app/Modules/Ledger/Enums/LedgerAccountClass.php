<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

enum LedgerAccountClass: string
{
    case ASSET = 'ASSET';
    case LIABILITY = 'LIABILITY';
    case EQUITY = 'EQUITY';
    case INCOME = 'INCOME';
    case EXPENSE = 'EXPENSE';

    public function normalBalance(): EntryDirection
    {
        return match ($this) {
            self::ASSET, self::EXPENSE => EntryDirection::DEBIT,
            self::LIABILITY, self::EQUITY, self::INCOME => EntryDirection::CREDIT,
        };
    }

    /** Code prefix used for auto-numbered accounts in the chart of accounts. */
    public function codePrefix(): string
    {
        return match ($this) {
            self::ASSET => '1',
            self::LIABILITY => '2',
            self::EQUITY => '3',
            self::INCOME => '4',
            self::EXPENSE => '5',
        };
    }
}
