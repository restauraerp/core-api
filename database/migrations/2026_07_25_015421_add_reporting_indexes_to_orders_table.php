<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The reporting endpoints scan orders by date range, optionally narrowed to
     * a single branch. The pre-existing idx_orders_status_date leads with
     * `status`, so it cannot serve those range scans - these two can.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('created_at', 'idx_orders_created_at');
            $table->index(['location_id', 'created_at'], 'idx_orders_location_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_created_at');
            $table->dropIndex('idx_orders_location_created_at');
        });
    }
};
