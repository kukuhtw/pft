<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Enums;

enum AccountStatus: string
{
    case ACTIVE = 'ACTIVE';
    case ARCHIVED = 'ARCHIVED';
    case CLOSED = 'CLOSED';
}
