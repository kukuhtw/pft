<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly spending limits per expense category. Budgets are plans, not money:
 * they never produce journal lines. "Actual" is always read from the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('category_id')->constrained('transaction_categories')->restrictOnDelete();
            $table->char('period', 7); // YYYY-MM in the display timezone
            $table->decimal('amount', 20, 2);
            $table->char('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->timestamps();

            $table->unique(['user_id', 'category_id', 'period']);
            $table->index(['user_id', 'period']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE budgets ADD CONSTRAINT chk_budgets_amount_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
