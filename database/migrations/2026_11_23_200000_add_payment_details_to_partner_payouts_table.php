<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_payouts', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('amount');
            $table->foreignId('cash_storage_id')->nullable()->after('payment_method')->constrained('cash_storages')->nullOnDelete();
            $table->unsignedInteger('orders_count')->default(0)->after('cash_storage_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('partner_payout_id')->nullable()->after('partner_id')->constrained('partner_payouts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_payout_id');
        });

        Schema::table('partner_payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_storage_id');
            $table->dropColumn(['payment_method', 'orders_count']);
        });
    }
};
