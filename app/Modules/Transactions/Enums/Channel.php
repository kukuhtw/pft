<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Enums;

enum Channel: string
{
    case WEB = 'WEB';
    case API = 'API';
    case TELEGRAM = 'TELEGRAM';
    case EXTENSION = 'EXTENSION';
    case IMPORT = 'IMPORT';
    case AI = 'AI';
    case SYSTEM = 'SYSTEM';
}
