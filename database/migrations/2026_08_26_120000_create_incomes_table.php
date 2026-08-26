<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money that comes in without an order behind it - hall rental, scrap
     * sales, an owner topping the float up.
     *
     * A separate table rather than a sign flip on `expenses`: every existing
     * read of that table (the profit report, the category breakdowns, the
     * reporting pages) sums `amount` unconditionally, so a negative row there
     * would silently understate spend everywhere at once. It also mirrors
     * expenses column for column, which is what the accounting screens expect.
     *
     * location_id is nullable from the start, the state expenses had to be
     * migrated into: income that belongs to the whole restaurant rather than
     * one branch is the normal case, not an exception.
     */
    public function up(): void
    {
        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->cascadeOnDelete();
            $table->string('category')->nullable();
            $table->foreignId('header_id')->nullable()->constrained('accounting_headers')->nullOnDelete();
            $table->decimal('amount', 10, 2)->nullable();
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('receipt_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incomes');
    }
};
