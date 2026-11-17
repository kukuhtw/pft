<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

enum LedgerAccountStatus: string
{
    case ACTIVE = 'ACTIVE';
    case ARCHIVED = 'ARCHIVED';
}
