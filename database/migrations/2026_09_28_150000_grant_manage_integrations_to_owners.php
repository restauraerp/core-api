<?php

use App\Models\Tenant;
use App\Support\Billing\Modules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hands the new manage_integrations permission (Integration > Telegram) to the
 * restaurants already live.
 *
 * New restaurants get it from the provisioner. Existing ones would otherwise
 * need RolePermissionSeeder and tenants:repair-roles run by hand - and the
 * latter re-syncs every predefined role to its defaults, which would undo any
 * permissions a restaurant has adjusted on its own roles. This does the one
 * thing needed instead: creates the permission and adds it to each owner role
 * whose restaurant has the Delivery module. Nothing else is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insertOrIgnore([
            'name' => 'manage_integrations',
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::table('permissions')
            ->where('name', 'manage_integrations')
            ->where('guard_name', 'web')
            ->value('id');

        $withDelivery = Tenant::withTrashed()->with('package')->get()
            ->filter(fn (Tenant $tenant) => $tenant->hasModule(Modules::DELIVERY))
            ->modelKeys();

        if ($permissionId !== null && $withDelivery !== []) {
            $ownerRoles = DB::table('roles')
                ->where('name', 'restaurant_admin')
                ->whereIn('tenant_id', $withDelivery)
                ->pluck('id');

            DB::table('role_has_permissions')->insertOrIgnore(
                $ownerRoles->map(fn ($roleId) => ['permission_id' => $permissionId, 'role_id' => $roleId])->all()
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'manage_integrations')->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
