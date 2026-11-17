<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Enums;

enum TransactionType: string
{
    case INCOME = 'INCOME';
    case EXPENSE = 'EXPENSE';
    /** Asset ↔ asset between the user's own accounts (incl. cash withdrawal). Never income/expense. */
    case INTERNAL_TRANSFER = 'INTERNAL_TRANSFER';
    /** Asset → liability: credit-card payment, loan principal repayment. Reduces a liability. */
    case LIABILITY_PAYMENT = 'LIABILITY_PAYMENT';
    /** Liability → asset: loan disbursement, cash advance. Increases a liability. */
    case LIABILITY_DRAWDOWN = 'LIABILITY_DRAWDOWN';
    /** Opening balances and authorised corrections. */
    case ADJUSTMENT = 'ADJUSTMENT';
}
