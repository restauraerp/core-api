<?php

use App\Models\Tenant;
use App\Support\Billing\Modules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hotel rooms, for restaurants that also run a hotel (the `hotel` add-on
 * module).
 *
 * - room_types: what a guest books - Deluxe Double, Family Suite - with
 *   capacity, a nightly rate and an optional weekend rate (Friday and
 *   Saturday nights), beds, size, amenities and photos.
 * - rooms: the actual rooms of each type, by number, any of which can be
 *   taken out of service.
 * - room_bookings: one stay in one room type, check-in to check-out. The
 *   nightly rates are copied onto it when it is priced, so changing a rate
 *   later never rewrites what was agreed. A room is assigned by check-in at
 *   the latest.
 * - room_booking_payments: the advance and the rest.
 *
 * And a manage_hotel permission for the owner and branch manager roles of
 * restaurants that have the module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('max_adults')->default(2);
            $table->unsignedSmallInteger('max_children')->default(0);
            $table->decimal('rate', 10, 2);
            $table->decimal('weekend_rate', 10, 2)->nullable();
            $table->string('beds', 120)->nullable();
            $table->unsignedSmallInteger('size_sqft')->nullable();
            $table->json('amenities')->nullable();
            $table->json('photos')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->string('number', 20);
            $table->string('floor', 20)->nullable();
            $table->boolean('in_service')->default(true);
            $table->string('notes', 300)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'room_type_id', 'number']);
        });

        Schema::create('room_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('guest_name', 100);
            $table->string('guest_phone', 20);
            $table->string('guest_email', 191)->nullable();
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('adults')->default(1);
            $table->unsignedSmallInteger('children')->default(0);
            // Night by night, as priced: [{date, rate}].
            $table->json('nightly_rates');
            $table->decimal('room_amount', 12, 2)->default(0);
            $table->json('extras')->nullable();
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('status', 20)->default('Pending');
            $table->string('source', 20)->default('staff');
            $table->text('notes')->nullable();
            $table->string('decline_reason', 300)->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamp('last_emailed_at')->nullable();
            $table->timestamps();

            $table->index(['room_type_id', 'check_in', 'check_out']);
            $table->index(['tenant_id', 'status', 'check_in']);
        });

        Schema::create('room_booking_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('room_booking_id')->constrained('room_bookings')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 20);
            $table->timestamp('paid_at');
            $table->string('note', 200)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table('permissions')->insertOrIgnore(['name' => 'manage_hotel', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        $permissionId = DB::table('permissions')->where('name', 'manage_hotel')->where('guard_name', 'web')->value('id');
        $withHotel = Tenant::withTrashed()->with('package')->get()
            ->filter(fn (Tenant $tenant) => $tenant->hasModule(Modules::HOTEL))
            ->modelKeys();
        if ($permissionId !== null && $withHotel !== []) {
            $roles = DB::table('roles')->whereIn('name', ['restaurant_admin', 'branch_manager'])->whereIn('tenant_id', $withHotel)->pluck('id');
            DB::table('role_has_permissions')->insertOrIgnore($roles->map(fn ($id) => ['permission_id' => $permissionId, 'role_id' => $id])->all());
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('room_booking_payments');
        Schema::dropIfExists('room_bookings');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('room_types');

        $permissionId = DB::table('permissions')->where('name', 'manage_hotel')->value('id');
        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
