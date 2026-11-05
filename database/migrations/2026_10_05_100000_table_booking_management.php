<?php

use App\Models\Tenant;
use App\Support\Billing\Modules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Table bookings the restaurant can actually manage.
 *
 * Each booking keeps the guest's name and phone as given - the customer
 * record is matched by phone and may carry an older name - so the number to
 * call is always on the booking itself. Then what happened to it: who
 * approved or declined it and when, why it was declined, and when the guest
 * was last called.
 *
 * And a manage_reservations permission (part of CRM, like bookings), given
 * to the owner, branch manager and POS manager roles of restaurants that
 * have CRM - front of house takes bookings. Nothing else is touched; see the
 * grant_manage_integrations migration for why not repair-roles.
 */
return new class extends Migration
{
    private const ROLES = ['restaurant_admin', 'branch_manager', 'pos_manager'];

    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('guest_name', 100)->nullable()->after('table_id');
            $table->string('guest_phone', 20)->nullable()->after('guest_name');
            $table->string('decline_reason', 300)->nullable()->after('notes');
            $table->foreignId('handled_by')->nullable()->after('decline_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable()->after('handled_by');
            $table->timestamp('called_at')->nullable()->after('handled_at');
            $table->foreignId('called_by')->nullable()->after('called_at')->constrained('users')->nullOnDelete();
        });

        // Existing bookings: the name and phone from their customer.
        DB::table('reservations')
            ->join('customers', 'customers.id', '=', 'reservations.customer_id')
            ->whereNull('reservations.guest_phone')
            ->update([
                'reservations.guest_name' => DB::raw('customers.name'),
                'reservations.guest_phone' => DB::raw('customers.phone'),
            ]);

        // One spelling per status.
        foreach (['pending' => 'Pending', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled', 'seated' => 'Seated', 'completed' => 'Completed'] as $from => $to) {
            DB::table('reservations')->where('status', $from)->update(['status' => $to]);
        }
        DB::table('reservations')->whereNull('status')->update(['status' => 'Pending']);

        $this->grant();
    }

    private function grant(): void
    {
        $now = now();
        DB::table('permissions')->insertOrIgnore(['name' => 'manage_reservations', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        $permissionId = DB::table('permissions')->where('name', 'manage_reservations')->where('guard_name', 'web')->value('id');

        $withCrm = Tenant::withTrashed()->with('package')->get()
            ->filter(fn (Tenant $tenant) => $tenant->hasModule(Modules::CRM))
            ->modelKeys();

        if ($permissionId !== null && $withCrm !== []) {
            $roles = DB::table('roles')->whereIn('name', self::ROLES)->whereIn('tenant_id', $withCrm)->pluck('id');
            DB::table('role_has_permissions')->insertOrIgnore(
                $roles->map(fn ($roleId) => ['permission_id' => $permissionId, 'role_id' => $roleId])->all()
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handled_by');
            $table->dropConstrainedForeignId('called_by');
            $table->dropColumn(['guest_name', 'guest_phone', 'decline_reason', 'handled_at', 'called_at']);
        });

        $permissionId = DB::table('permissions')->where('name', 'manage_reservations')->value('id');
        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
