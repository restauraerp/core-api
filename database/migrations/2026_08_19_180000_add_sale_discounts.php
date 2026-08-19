<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discounts a cashier decides on, as opposed to a coupon somebody redeems.
 *
 * `discounts` already existed and is a coupon table - a code, a value, an
 * expiry - which is a different thing from "take 50 off this steak because it
 * came out cold" or "10% off the whole bill, the owner said so". Those need no
 * code, exist only for one sale, and are exactly what the till was missing:
 * `order_items` had nowhere at all to record a discount.
 *
 * Both levels store the type and the value the cashier chose. The resulting
 * amount is computed and stored too, for the same reason tax_amount is: the
 * rate is an instruction, the amount is a fact about that sale, and a receipt
 * reprinted next year must show what was actually taken off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Separate from discount_id, which is the redeemed coupon. An order
            // can carry both: a coupon off the bill and a further goodwill
            // reduction on top.
            $table->string('discount_type')->nullable()->after('discount_amount');
            $table->decimal('discount_value', 10, 2)->nullable()->after('discount_type');
            $table->text('discount_reason')->nullable()->after('discount_value');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('discount_type')->nullable()->after('price');
            $table->decimal('discount_value', 10, 2)->nullable()->after('discount_type');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('discount_value');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'discount_amount']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'discount_reason']);
        });
    }
};
