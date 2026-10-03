<?php

use App\Models\Tenant;
use App\Support\Billing\Modules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Venue booking: a restaurant's halls hired for events - birthdays, weddings,
 * holud, aqiqah, corporate dinners.
 *
 * - halls become bookable venues: rent per event or per hour (`price`, as
 *   before, is the rent), a guest range, the cleanup time kept free between
 *   events, a description, and whether it is offered at all.
 * - venue_packages: per-person food menus a booking can include.
 * - venue_bookings: one event in one hall. Prices are copied onto the booking
 *   when it is priced, so changing a hall's rent or a package later never
 *   rewrites what a customer agreed to.
 * - venue_booking_payments: the advance and later payments against it.
 *
 * And a manage_venue_bookings permission (CRM module, like table bookings)
 * for the owner and branch manager roles of restaurants that have CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('halls', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('rent_type', 10)->default('per_event')->after('price');
            $table->unsignedInteger('min_guests')->nullable()->after('capacity');
            $table->unsignedInteger('buffer_minutes')->default(60)->after('rent_type');
            $table->boolean('is_active')->default(true)->after('buffer_minutes');
        });

        Schema::create('venue_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('price_per_person', 10, 2);
            $table->unsignedInteger('min_guests')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('venue_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('hall_id')->constrained('halls')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('guest_name', 100);
            $table->string('guest_phone', 20);
            $table->string('event_type', 30);
            $table->string('event_title', 150)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('guests');
            $table->foreignId('package_id')->nullable()->constrained('venue_packages')->nullOnDelete();
            $table->string('package_name', 120)->nullable();
            $table->decimal('package_price', 10, 2)->default(0);
            $table->decimal('rent_amount', 12, 2)->default(0);
            $table->json('extras')->nullable();
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('status', 20)->default('Pending');
            $table->string('source', 20)->default('staff');
            $table->text('notes')->nullable();
            $table->string('decline_reason', 300)->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamp('called_at')->nullable();
            $table->foreignId('called_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hall_id', 'starts_at']);
            $table->index(['tenant_id', 'status', 'starts_at']);
        });

        Schema::create('venue_booking_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('venue_booking_id')->constrained('venue_bookings')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 20);
            $table->timestamp('paid_at');
            $table->string('note', 200)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->grant();
    }

    private function grant(): void
    {
        $now = now();
        DB::table('permissions')->insertOrIgnore(['name' => 'manage_venue_bookings', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        $permissionId = DB::table('permissions')->where('name', 'manage_venue_bookings')->where('guard_name', 'web')->value('id');

        $withCrm = Tenant::withTrashed()->with('package')->get()
            ->filter(fn (Tenant $tenant) => $tenant->hasModule(Modules::CRM))
            ->modelKeys();

        if ($permissionId !== null && $withCrm !== []) {
            $roles = DB::table('roles')->whereIn('name', ['restaurant_admin', 'branch_manager'])->whereIn('tenant_id', $withCrm)->pluck('id');
            DB::table('role_has_permissions')->insertOrIgnore(
                $roles->map(fn ($roleId) => ['permission_id' => $permissionId, 'role_id' => $roleId])->all()
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_booking_payments');
        Schema::dropIfExists('venue_bookings');
        Schema::dropIfExists('venue_packages');
        Schema::table('halls', function (Blueprint $table) {
            $table->dropColumn(['description', 'rent_type', 'min_guests', 'buffer_minutes', 'is_active']);
        });

        $permissionId = DB::table('permissions')->where('name', 'manage_venue_bookings')->value('id');
        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
