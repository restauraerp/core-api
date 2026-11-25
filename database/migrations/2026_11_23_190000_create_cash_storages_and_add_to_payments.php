<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_storages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type', 32); // 'cash', 'card', 'bkash'
            $table->decimal('opening_balance', 12, 2)->default(0.00);
            $table->decimal('current_balance', 12, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'location_id']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('cash_storage_id')
                ->nullable()
                ->after('method')
                ->constrained('cash_storages')
                ->nullOnDelete();
        });

        // Seed standard default cash storages for all existing active restaurants
        // so existing tenants can immediately categorize and track cash without manual setup.
        $tenants = DB::table('tenants')->get();
        $now = now();

        foreach ($tenants as $tenant) {
            DB::table('cash_storages')->insert([
                [
                    'tenant_id' => $tenant->id,
                    'location_id' => null,
                    'name' => 'Main Cash Drawer',
                    'type' => 'cash',
                    'opening_balance' => 0.00,
                    'current_balance' => 0.00,
                    'is_active' => true,
                    'notes' => 'Primary cash drawer/till for physical cash collections',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'tenant_id' => $tenant->id,
                    'location_id' => null,
                    'name' => 'Card POS Terminal',
                    'type' => 'card',
                    'opening_balance' => 0.00,
                    'current_balance' => 0.00,
                    'is_active' => true,
                    'notes' => 'Primary card POS machine for credit/debit card collections',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'tenant_id' => $tenant->id,
                    'location_id' => null,
                    'name' => 'bKash Merchant Account',
                    'type' => 'bkash',
                    'opening_balance' => 0.00,
                    'current_balance' => 0.00,
                    'is_active' => true,
                    'notes' => 'Primary bKash / MFS mobile wallet for customer collections',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['cash_storage_id']);
            $table->dropColumn('cash_storage_id');
        });

        Schema::dropIfExists('cash_storages');
    }
};
