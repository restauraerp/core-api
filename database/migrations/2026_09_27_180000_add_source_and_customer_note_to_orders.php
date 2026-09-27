<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an order came from, and what the customer asked for with it.
 *
 * `source` is null for everything rung up by staff (the till, the kiosk) and
 * 'online' for orders customers place themselves through the restaurant's
 * online order link, so the floor can tell at a glance which orders arrived
 * without anyone taking them. `customer_note` is the free text a customer adds
 * at checkout - "ring the bell", "no onions on anything".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('order_type');
            $table->text('customer_note')->nullable()->after('delivery_address');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['source', 'customer_note']);
        });
    }
};
