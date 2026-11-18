<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan hutang: what a loan was taken for ("bayar sekolah", "modal usaha"), so the debt register can
 * answer when / from whom / for what. Set on LOAN_RECEIVED entries.
 *
 * Data minimisation (ISO/IEC 27001 A.8.11): a short free-text label only (max 128 chars); the UI asks
 * the user not to enter identity numbers, account numbers or health details here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('purpose', 128)->nullable()->after('activity');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
