<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Money lent to someone else is still the user's (an asset), not spending. A "Piutang" account holds it:
 * lending = transfer from a bank/cash account to the receivable, repayment = transfer back.
 * Transfers never count as income or expense, and the receivable stays in net worth.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('account_types')->insertOrIgnore([
            'code' => 'RECEIVABLE',
            'name' => 'Piutang (uang dipinjamkan ke orang lain)',
            'ledger_class' => 'ASSET',
            'is_liquid' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Keep the type if accounts already use it (financial records are never deleted).
        if (! DB::table('financial_accounts')->where('account_type_code', 'RECEIVABLE')->exists()) {
            DB::table('account_types')->where('code', 'RECEIVABLE')->delete();
        }
    }
};
