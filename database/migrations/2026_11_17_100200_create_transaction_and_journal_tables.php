<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business transactions and the double-entry journal.
 * journal_entries / journal_lines are append-only (immutability enforced in the model layer
 * and by CHECK constraints on MySQL/MariaDB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['INCOME', 'EXPENSE', 'INTERNAL_TRANSFER', 'LIABILITY_PAYMENT', 'LIABILITY_DRAWDOWN', 'ADJUSTMENT']);
            $table->enum('status', ['DRAFT', 'PENDING_CONFIRMATION', 'POSTED', 'REVERSED', 'FAILED'])->default('DRAFT');
            $table->date('transaction_date');
            $table->decimal('amount', 20, 2);
            $table->char('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->foreignUlid('source_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignUlid('destination_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained('transaction_categories')->restrictOnDelete();
            $table->foreignUlid('merchant_id')->nullable()->constrained('merchants')->nullOnDelete();
            $table->string('description', 255)->nullable();
            $table->string('reference_number', 64)->nullable();
            $table->enum('payment_method', ['CASH', 'BANK_TRANSFER', 'QRIS', 'DEBIT_CARD', 'CREDIT_CARD', 'EWALLET', 'VIRTUAL_ACCOUNT', 'OTHER'])->nullable();
            $table->enum('channel', ['WEB', 'API', 'TELEGRAM', 'EXTENSION', 'IMPORT', 'AI', 'SYSTEM'])->default('API');
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reversal_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'transaction_date']);
            $table->index(['user_id', 'type', 'transaction_date']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->enum('entry_type', ['STANDARD', 'REVERSAL', 'OPENING_BALANCE', 'ADJUSTMENT']);
            $table->foreignUlid('reverses_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->date('entry_date');
            $table->string('description', 255)->nullable();
            $table->char('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->decimal('total_debit', 20, 2);
            $table->decimal('total_credit', 20, 2);
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'entry_date']);
            $table->index(['transaction_id', 'entry_type']);
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->enum('direction', ['DEBIT', 'CREDIT']);
            $table->decimal('amount', 20, 2);
            $table->string('memo', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['journal_entry_id', 'line_no']);
            $table->index(['ledger_account_id', 'journal_entry_id']);
            $table->index(['user_id', 'ledger_account_id']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE transactions ADD CONSTRAINT chk_transactions_amount_positive CHECK (amount > 0)');
            DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT chk_journal_lines_amount_positive CHECK (amount > 0)');
            DB::statement('ALTER TABLE journal_entries ADD CONSTRAINT chk_journal_entries_balanced CHECK (total_debit = total_credit AND total_debit > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('transactions');
    }
};
