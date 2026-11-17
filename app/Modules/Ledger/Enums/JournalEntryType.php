<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

enum JournalEntryType: string
{
    case STANDARD = 'STANDARD';
    case REVERSAL = 'REVERSAL';
    case OPENING_BALANCE = 'OPENING_BALANCE';
    case ADJUSTMENT = 'ADJUSTMENT';
}
