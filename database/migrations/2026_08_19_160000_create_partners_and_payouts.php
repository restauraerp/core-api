<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders that arrive through somebody else.
 *
 * A delivery aggregator sends the restaurant an order, the restaurant cooks it,
 * and the aggregator keeps a cut - typically a quarter - and pays the rest over
 * some weeks later. Two things follow, and neither is served by the ordinary
 * order tables: the sale is worth less than it says on the bill, and the money
 * is owed by the partner rather than by the diner.
 *
 * The commission rate lives on the partner, but the *applied* rate and the
 * amounts it produced are copied onto each order. Rates get renegotiated, and
 * recomputing history against today's rate would silently restate what the
 * restaurant earned last quarter. Same reasoning as tax_amount on orders: the
 * rate is configuration, the amount is a fact about that sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            // The share the partner keeps, as a percentage. 25 is what the
            // aggregators in this market ask for and what the restaurant asked
            // us to default to.
            $table->decimal('commission_rate', 5, 2)->default(25);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('partner_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('partners')
                // A partner the restaurant stops working with must not take the
                // orders they brought - and the money owed on them - with it.
                ->nullOnDelete();

            // Copied at the time of sale. See the class note above.
            $table->decimal('partner_commission_rate', 5, 2)->nullable()->after('partner_id');
            $table->decimal('partner_commission_amount', 10, 2)->nullable()->after('partner_commission_rate');

            $table->index(['partner_id', 'created_at'], 'idx_orders_partner_date');
        });

        Schema::create('partner_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('received_on');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'partner_id', 'received_on'], 'idx_payouts_partner_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_payouts');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_partner_date');
            $table->dropColumn(['partner_commission_rate', 'partner_commission_amount']);
            $table->dropConstrainedForeignId('partner_id');
        });

        Schema::dropIfExists('partners');
    }
};
