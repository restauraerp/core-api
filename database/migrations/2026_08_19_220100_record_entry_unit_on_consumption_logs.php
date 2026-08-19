<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the cook actually typed, alongside what left the shelf.
 *
 * Once an item can be counted in two units, "3" is ambiguous - three sacks or
 * three kilos. `quantity` stays what was entered, so the log reads back the way
 * it was written; `stock_quantity` is what that came to in purchase units and
 * is what stock moved by.
 *
 * Both are stored rather than one being derived, for the reason every other
 * computed amount here is stored: the factor is configuration and can be
 * renegotiated the day somebody switches supplier, and recomputing history
 * against a new factor would restate what the kitchen used last month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumption_logs', function (Blueprint $table) {
            $table->string('entry_unit')->default('purchase')->after('quantity');
            $table->decimal('stock_quantity', 12, 4)->nullable()->after('entry_unit');
        });

        // Existing logs were all entered in the item's only unit, which is now
        // the purchase unit, so what moved is what was typed.
        DB::statement('UPDATE consumption_logs SET stock_quantity = quantity WHERE stock_quantity IS NULL');
    }

    public function down(): void
    {
        Schema::table('consumption_logs', function (Blueprint $table) {
            $table->dropColumn(['entry_unit', 'stock_quantity']);
        });
    }
};
