<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders a restaurant has agreed to be paid for later.
 *
 * `payment_status` is a plain string column, so "due" needs no schema change of
 * its own - what it needs is a note saying who agreed to it and why, which is
 * the whole point of letting an order leave unpaid. A resident hotel guest
 * charging dinner to room 402 is the case this exists for, and "room 402" is
 * exactly the thing that must not live only in a waiter's memory.
 *
 * No separate `amount_paid` column. What has been paid is the sum of the
 * order's payments and is derived from them; a second copy of that number is a
 * second thing to keep in step, and the one that drifts is always the copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('due_note')->nullable()->after('payment_status');

            // The Due tab lists them newest first, per restaurant.
            $table->index(['payment_status', 'created_at'], 'idx_orders_payment_status_date');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_payment_status_date');
            $table->dropColumn('due_note');
        });
    }
};
