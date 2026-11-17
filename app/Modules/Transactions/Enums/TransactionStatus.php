<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Enums;

enum TransactionStatus: string
{
    case DRAFT = 'DRAFT';
    case PENDING_CONFIRMATION = 'PENDING_CONFIRMATION';
    case POSTED = 'POSTED';
    case REVERSED = 'REVERSED';
    case FAILED = 'FAILED';

    public function isFinal(): bool
    {
        return in_array($this, [self::POSTED, self::REVERSED], true);
    }
}
