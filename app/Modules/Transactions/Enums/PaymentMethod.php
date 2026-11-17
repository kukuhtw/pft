<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Enums;

enum PaymentMethod: string
{
    case CASH = 'CASH';
    case BANK_TRANSFER = 'BANK_TRANSFER';
    case QRIS = 'QRIS';
    case DEBIT_CARD = 'DEBIT_CARD';
    case CREDIT_CARD = 'CREDIT_CARD';
    case EWALLET = 'EWALLET';
    case VIRTUAL_ACCOUNT = 'VIRTUAL_ACCOUNT';
    case OTHER = 'OTHER';
}
