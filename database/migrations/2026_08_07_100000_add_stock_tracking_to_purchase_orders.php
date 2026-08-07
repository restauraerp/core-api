<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders now move stock, so they need to remember whether they already
 * have.
 *
 * Without this flag every edit would have to guess whether the quantities on
 * the order are still sitting in inventory, and a cancel-then-reopen would
 * double-count the delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->boolean('stock_applied')->default(false)->after('status');
            $table->text('notes')->nullable()->after('stock_applied');
        });

        // Line rows were created without them; every other table has them and
        // the API returns these rows directly.
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->timestamps();
        });

        // Existing demo and historical orders were seeded as already received,
        // and the stock levels on hand were seeded to match them.
        DB::table('purchase_orders')->where('status', 'received')->update(['stock_applied' => true]);
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['stock_applied', 'notes']);
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropTimestamps();
        });
    }
};
