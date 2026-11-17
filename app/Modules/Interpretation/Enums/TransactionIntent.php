<?php

declare(strict_types=1);

namespace App\Modules\Interpretation\Enums;

use App\Modules\Transactions\Enums\TransactionType;

/**
 * What a transaction *means* to the user. Stable codes shared by the ledger, reports, heuristics and LLMs.
 * Unlike TransactionType (the accounting shape) this distinguishes e.g. salary from income earned by an activity.
 */
enum TransactionIntent: string
{
    /** Gaji, THR, bonus, lembur from an employer. Ledger: INCOME (category SALARY). */
    case SALARY_INCOME = 'SALARY_INCOME';
    /** Earned from a specific activity: selling, freelance project, teaching, rent… Ledger: INCOME. */
    case ACTIVITY_INCOME = 'ACTIVITY_INCOME';
    /** Interest, gifts, refunds and other income. Ledger: INCOME. */
    case OTHER_INCOME = 'OTHER_INCOME';
    /** Money borrowed from a party (pinjaman/hutang masuk). Ledger: LIABILITY_DRAWDOWN — never income. */
    case LOAN_RECEIVED = 'LOAN_RECEIVED';
    /** Payment to a party for a specific debt (bayar hutang/cicilan/kartu kredit). Ledger: LIABILITY_PAYMENT — never expense. */
    case DEBT_REPAYMENT = 'DEBT_REPAYMENT';
    case EXPENSE = 'EXPENSE';
    case INTERNAL_TRANSFER = 'INTERNAL_TRANSFER';
    case OPENING_BALANCE = 'OPENING_BALANCE';
    /** Today's balance set to the real figure (e.g. from m-banking). Against equity: never income or expense. */
    case BALANCE_ADJUSTMENT = 'BALANCE_ADJUSTMENT';

    /** System-posted intents that a user cannot pick when recording an entry. */
    public function isSystem(): bool
    {
        return in_array($this, [self::OPENING_BALANCE, self::BALANCE_ADJUSTMENT], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::SALARY_INCOME => 'Pemasukan gaji',
            self::ACTIVITY_INCOME => 'Pemasukan dari aktivitas',
            self::OTHER_INCOME => 'Pemasukan lainnya',
            self::LOAN_RECEIVED => 'Pinjaman diterima',
            self::DEBT_REPAYMENT => 'Pembayaran hutang',
            self::EXPENSE => 'Pengeluaran',
            self::INTERNAL_TRANSFER => 'Transfer antar akun sendiri',
            self::OPENING_BALANCE => 'Saldo awal',
            self::BALANCE_ADJUSTMENT => 'Penyesuaian saldo',
        };
    }

    /** The API operation that posts this intent correctly (relative to /api/v1/transactions). */
    public function operation(): ?string
    {
        return match ($this) {
            self::SALARY_INCOME, self::ACTIVITY_INCOME, self::OTHER_INCOME => 'income',
            self::LOAN_RECEIVED => 'loan-received',
            self::DEBT_REPAYMENT => 'debt-payment',
            self::EXPENSE => 'expense',
            self::INTERNAL_TRANSFER => 'transfer',
            self::OPENING_BALANCE, self::BALANCE_ADJUSTMENT => null,
        };
    }

    public function isIncome(): bool
    {
        return in_array($this, [self::SALARY_INCOME, self::ACTIVITY_INCOME, self::OTHER_INCOME], true);
    }

    /** The ledger transaction types this intent is allowed to be posted as. */
    public function compatibleWith(TransactionType $type): bool
    {
        return match ($this) {
            self::SALARY_INCOME, self::ACTIVITY_INCOME, self::OTHER_INCOME => $type === TransactionType::INCOME,
            self::LOAN_RECEIVED => $type === TransactionType::LIABILITY_DRAWDOWN,
            self::DEBT_REPAYMENT => $type === TransactionType::LIABILITY_PAYMENT,
            self::EXPENSE => $type === TransactionType::EXPENSE,
            self::INTERNAL_TRANSFER => $type === TransactionType::INTERNAL_TRANSFER,
            self::OPENING_BALANCE, self::BALANCE_ADJUSTMENT => $type === TransactionType::ADJUSTMENT,
        };
    }
}
