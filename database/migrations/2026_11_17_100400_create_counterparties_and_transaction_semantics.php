<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Machine-readable semantics for every posted entry:
 *  - counterparties: the party money came from / went to (employer, lender, friend, client…)
 *  - financial_accounts.counterparty_id: which party a LOAN / CREDIT_CARD liability is owed to
 *  - transactions.intent / counterparty_id / activity: what the entry means (salary, loan received,
 *    debt repayment, income from an activity…) so reports, heuristics and LLMs read the same facts.
 *
 * Data minimisation (ISO/IEC 27001 A.8.11): only a display name and optional aliases are stored for a
 * counterparty, never phone numbers, national IDs or account numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counterparties', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 128);
            // Lower-cased, honorifics ("pak", "bu", "mas"…) and punctuation removed — used for matching.
            $table->string('normalized_name', 128);
            $table->enum('kind', ['PERSON', 'ORGANIZATION', 'UNKNOWN'])->default('UNKNOWN');
            $table->string('aliases', 255)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'normalized_name']);
        });

        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->foreignUlid('counterparty_id')->nullable()->after('financial_institution_id')
                ->constrained('counterparties')->restrictOnDelete();
            $table->index(['user_id', 'counterparty_id']);
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('intent', 32)->nullable()->after('type');
            $table->foreignUlid('counterparty_id')->nullable()->after('merchant_id')
                ->constrained('counterparties')->restrictOnDelete();
            $table->string('activity', 64)->nullable()->after('counterparty_id');

            $table->index(['user_id', 'intent', 'transaction_date']);
            $table->index(['user_id', 'counterparty_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'counterparty_id']);
            $table->dropIndex(['user_id', 'intent', 'transaction_date']);
            $table->dropConstrainedForeignId('counterparty_id');
            $table->dropColumn(['intent', 'activity']);
        });

        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'counterparty_id']);
            $table->dropConstrainedForeignId('counterparty_id');
        });

        Schema::dropIfExists('counterparties');
    }
};
