<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('partner_order_id', 255)->nullable()->after('partner_commission_amount');
            $table->index(['partner_id', 'partner_order_id'], 'idx_orders_partner_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_partner_order_id');
            $table->dropColumn('partner_order_id');
        });
    }
};
