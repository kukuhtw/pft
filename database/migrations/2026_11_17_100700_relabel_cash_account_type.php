<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** "Dompet Tunai" was easy to miss when looking for cash; make the physical-cash option explicit. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('account_types')->where('code', 'CASH')->update(['name' => 'Uang Tunai / Kas (dompet fisik)', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('account_types')->where('code', 'CASH')->update(['name' => 'Dompet Tunai', 'updated_at' => now()]);
    }
};
