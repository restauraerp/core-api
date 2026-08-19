<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Some money belongs to the restaurant rather than to a branch.
 *
 * A delivery aggregator settles a fortnight of trading across every outlet in
 * one transfer. Forcing that onto a single location would attribute Gulshan's
 * share to Banani; splitting it would be inventing a breakdown the payout does
 * not carry. `expenses.location_id` was made nullable for the same reason and
 * the accounting filter already offers "General Purpose (No Branch)", so these
 * entries have somewhere to appear.
 *
 * nullOnDelete rather than cascade, matching expenses: closing a branch must
 * not delete the ledger entries that record what it earned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_ledgers', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->foreignId('location_id')->nullable()->change()->constrained('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_ledgers', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->foreignId('location_id')->nullable(false)->change()->constrained('locations')->cascadeOnDelete();
        });
    }
};
