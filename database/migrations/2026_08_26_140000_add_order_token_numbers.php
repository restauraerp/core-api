<?php

use Illuminate\Database\Migrations\Migration;
use App\Support\Orders\TokenNumber;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The number the counter calls out - 1, 2, 3 - restarting each business day.
     *
     * `business_date` is stored rather than derived on read. The day a token
     * belongs to is not DATE(created_at): the counter rolls at 00:15, so an
     * order rung up at ten past midnight belongs to the day that is closing,
     * not the one starting. Writing that decision down once, at insert, is what
     * lets the unique index below exist at all - and it keeps yesterday's slips
     * reprinting the same token even if the roll-over time is changed later.
     *
     * See App\Support\Orders\TokenNumber, which owns both the boundary and the
     * allocation.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->date('business_date')->nullable()->after('location_id');
            $table->unsignedInteger('token_number')->nullable()->after('business_date');
        });

        // One row per branch per day, holding the last number handed out.
        //
        // A counter table rather than MAX(token_number)+1 over orders: two
        // tills ringing up at the same moment both read the same MAX and both
        // print token 41. The upsert in TokenNumber::allocate() locks this one
        // row instead, which is also why it is keyed exactly like the index.
        Schema::create('order_token_counters', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->date('business_date');
            $table->unsignedInteger('last_token')->default(0);
            $table->timestamps();

            // Deliberately no surrogate id. An AUTO_INCREMENT column would set
            // LAST_INSERT_ID() itself on the insert branch of the upsert,
            // overwriting the token value the allocation reads back out - the
            // first order of every day would be handed the counter row's id
            // instead of 1. The natural key is the key.
            $table->primary(['tenant_id', 'location_id', 'business_date']);
        });

        // Existing orders get the numbers they would have been given, so a slip
        // reprinted for last Tuesday is not the only one on the spike with no
        // token on it, and each counter is left pointing at the day's highest.
        // The rule lives in TokenNumber so this and the demo seeder cannot
        // drift apart on where the day breaks.
        TokenNumber::numberExistingOrders();

        // Added after the backfill on purpose: if the numbering above were ever
        // wrong, this fails the migration rather than letting duplicates sit in
        // the table waiting to be printed.
        Schema::table('orders', function (Blueprint $table) {
            $table->unique(
                ['tenant_id', 'location_id', 'business_date', 'token_number'],
                'uniq_orders_daily_token',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_token_counters');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('uniq_orders_daily_token');
            $table->dropColumn(['business_date', 'token_number']);
        });
    }
};
