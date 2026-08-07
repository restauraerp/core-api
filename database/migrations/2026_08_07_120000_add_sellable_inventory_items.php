<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Some stock is sold exactly as it was bought - a bottle of water, a packet of
 * crisps, a can of soft drink. Marking such an item sellable puts it on the
 * till.
 *
 * It gets there as a real product rather than a second kind of sellable thing:
 * orders, order lines, receipts and every sales report already speak product,
 * and teaching all of them about a second one would be a much larger change
 * than keeping one mirrored row in step. `products.inventory_item_id` is that
 * link, and it is what stops a second tick creating a second product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->boolean('is_sellable')->default(false)->after('cost_per_unit');
            $table->decimal('selling_price', 10, 2)->nullable()->after('is_sellable');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('inventory_item_id')
                ->nullable()
                ->after('category_id')
                ->constrained('inventory_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_item_id');
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['is_sellable', 'selling_price']);
        });
    }
};
