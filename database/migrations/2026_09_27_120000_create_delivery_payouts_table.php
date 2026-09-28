<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery charges paid back out to riders or couriers.
 *
 * Each payout also writes an Expense (see DeliveryPayoutController), so it
 * reaches the Profit report and the accounting reports without any of them
 * having to know about this table. This row keeps what the expense cannot:
 * who was paid, for which outlet, and the reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('rider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'paid_on'], 'idx_delivery_payouts_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_payouts');
    }
};
