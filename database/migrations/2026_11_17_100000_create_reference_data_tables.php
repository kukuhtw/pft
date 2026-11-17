<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Reference data shared by all tenants: currencies, account types, financial institutions.
 * Seed rows are part of the schema contract (the application depends on these codes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->char('code', 3)->primary();
            $table->string('name', 64);
            $table->string('symbol', 8);
            $table->unsignedTinyInteger('minor_unit')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('account_types', function (Blueprint $table): void {
            $table->string('code', 32)->primary();
            $table->string('name', 64);
            $table->enum('ledger_class', ['ASSET', 'LIABILITY']);
            $table->boolean('is_liquid')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('financial_institutions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name', 128);
            $table->enum('kind', ['BANK', 'EWALLET', 'LENDER', 'OTHER']);
            $table->char('country_code', 2)->default('ID');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        DB::table('currencies')->insert([
            ['code' => 'IDR', 'name' => 'Rupiah Indonesia', 'symbol' => 'Rp', 'minor_unit' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'minor_unit' => 2, 'is_active' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('account_types')->insert([
            ['code' => 'BANK', 'name' => 'Rekening Bank', 'ledger_class' => 'ASSET', 'is_liquid' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CASH', 'name' => 'Dompet Tunai', 'ledger_class' => 'ASSET', 'is_liquid' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'EWALLET', 'name' => 'Dompet Digital', 'ledger_class' => 'ASSET', 'is_liquid' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CREDIT_CARD', 'name' => 'Kartu Kredit', 'ledger_class' => 'LIABILITY', 'is_liquid' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'LOAN', 'name' => 'Pinjaman', 'ledger_class' => 'LIABILITY', 'is_liquid' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'INVESTMENT', 'name' => 'Investasi', 'ledger_class' => 'ASSET', 'is_liquid' => false, 'is_active' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $institutions = [
            ['BCA', 'Bank Central Asia', 'BANK'],
            ['BNI', 'Bank Negara Indonesia', 'BANK'],
            ['BRI', 'Bank Rakyat Indonesia', 'BANK'],
            ['MANDIRI', 'Bank Mandiri', 'BANK'],
            ['CIMB', 'CIMB Niaga', 'BANK'],
            ['BSI', 'Bank Syariah Indonesia', 'BANK'],
            ['PERMATA', 'Bank Permata', 'BANK'],
            ['JAGO', 'Bank Jago', 'BANK'],
            ['GOPAY', 'GoPay', 'EWALLET'],
            ['OVO', 'OVO', 'EWALLET'],
            ['DANA', 'DANA', 'EWALLET'],
            ['SHOPEEPAY', 'ShopeePay', 'EWALLET'],
        ];

        DB::table('financial_institutions')->insert(array_map(static fn (array $i): array => [
            'id' => (string) Str::ulid(),
            'code' => $i[0],
            'name' => $i[1],
            'kind' => $i[2],
            'country_code' => 'ID',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $institutions));
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_institutions');
        Schema::dropIfExists('account_types');
        Schema::dropIfExists('currencies');
    }
};
