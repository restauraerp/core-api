<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who should be credited for the sale, which is not always who rang it up.
 *
 * `orders.user_id` already records the account that created the order, and it
 * stays what it is: an audit trail of who touched the system. It cannot double
 * as the credit, because a till is very often run under one shared login - so
 * grouping sales by user_id would report that "POS Terminal" served every
 * customer in the restaurant, and an employee performance report built on it
 * would be worthless.
 *
 * Nullable, because plenty of orders have nobody to credit - a walk-in rung
 * through quickly, an order placed from the storefront - and forcing a name
 * onto those would put noise into the very report this exists to make useful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('served_by_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                // An employee who leaves must not take the sales history with
                // them, so their orders survive and simply stop naming anyone.
                ->nullOnDelete();

            // The performance report groups by employee over a date window.
            $table->index(['served_by_user_id', 'created_at'], 'idx_orders_served_by_date');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_served_by_date');
            $table->dropConstrainedForeignId('served_by_user_id');
        });
    }
};
