<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records which cycle a tenant pays on.
     *
     * Needed because the grace period after a missed renewal differs by cycle -
     * 7 days on monthly, 14 on yearly, since an annual invoice usually goes
     * through a bank transfer rather than a card charge. Without this column
     * `subscription_ends_at` alone cannot say how long a lapsed tenant keeps
     * full access.
     *
     * NULL means the tenant has never subscribed (still on trial, or created
     * before subscriptions were tracked). Trials get no grace at all, so a NULL
     * here is not a missing value to backfill.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->enum('billing_cycle', ['monthly', 'yearly'])
                ->nullable()
                ->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('billing_cycle');
        });
    }
};
