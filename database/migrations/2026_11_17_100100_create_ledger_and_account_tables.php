<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity preferences, chart of accounts (ledger_accounts), user-facing financial accounts,
 * categories and merchants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('locale', 8)->default('id');
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->char('default_currency', 3)->default('IDR');
            $table->foreign('default_currency')->references('code')->on('currencies');
            $table->timestamps();
        });

        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('code', 16);
            $table->string('name', 128);
            $table->enum('class', ['ASSET', 'LIABILITY', 'EQUITY', 'INCOME', 'EXPENSE']);
            $table->char('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('currencies');
            // Projection of Σ journal lines in the account's normal-balance sign. Only LedgerPostingService writes it.
            $table->decimal('balance', 20, 2)->default(0);
            $table->boolean('is_system')->default(false);
            $table->enum('status', ['ACTIVE', 'ARCHIVED'])->default('ACTIVE');
            $table->timestamps();

            $table->unique(['user_id', 'code']);
            $table->index(['user_id', 'class']);
        });

        Schema::create('financial_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('ledger_account_id')->unique()->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('account_type_code', 32);
            $table->foreign('account_type_code')->references('code')->on('account_types');
            $table->foreignUlid('financial_institution_id')->nullable()->constrained('financial_institutions')->nullOnDelete();
            $table->string('name', 128);
            $table->char('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('currencies');
            // Data minimisation (ISO 27001 A.8.11): never store full account / card numbers.
            $table->char('account_number_last4', 4)->nullable();
            $table->decimal('opening_balance', 20, 2)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->boolean('allow_negative_balance')->default(false);
            $table->enum('status', ['ACTIVE', 'ARCHIVED', 'CLOSED'])->default('ACTIVE');
            $table->string('aliases', 255)->nullable(); // e.g. "niaga,cimb" — used by future NL parser
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('transaction_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('ledger_account_id')->unique()->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('transaction_categories')->nullOnDelete();
            $table->string('code', 48);
            $table->string('name', 128);
            $table->enum('kind', ['INCOME', 'EXPENSE']);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'code']);
            $table->index(['user_id', 'kind']);
        });

        Schema::create('merchants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 128);
            $table->string('normalized_name', 128);
            $table->foreignUlid('default_category_id')->nullable()->constrained('transaction_categories')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'normalized_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
        Schema::dropIfExists('transaction_categories');
        Schema::dropIfExists('financial_accounts');
        Schema::dropIfExists('ledger_accounts');
        Schema::dropIfExists('user_preferences');
    }
};
