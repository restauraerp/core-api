<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock bought in one unit and used in another.
 *
 * Rice arrives in 50kg sacks and leaves the store in grams; oil is bought by
 * the drum and cooked with by the litre. With one unit per item the kitchen
 * either reports consumption in sacks - and writes 0.004 - or the buyer records
 * deliveries in grams. Both are how a stock figure stops being trusted.
 *
 * `unit` keeps its meaning: it is the purchase unit, which is what stock is
 * counted and valued in. That choice is deliberate. Every existing row, every
 * purchase order, every stock-value report and every cost_per_unit already
 * means "per `unit`", so redefining it would restate the value of every
 * restaurant's store room. The new column is the smaller unit hanging off it.
 *
 * A factor of 1 - the default - means the two units are the same, which is
 * exactly what every existing item is. Nothing converts until somebody says a
 * sack holds 50 kg.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            // What the kitchen counts in. Null means "same as the purchase unit".
            $table->string('sale_unit')->nullable()->after('unit');
            // How many sale units are in one purchase unit: a 50kg sack is 50.
            $table->decimal('sale_units_per_purchase_unit', 12, 4)->default(1)->after('sale_unit');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['sale_unit', 'sale_units_per_purchase_unit']);
        });
    }
};
