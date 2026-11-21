<?php

use App\Models\Tenant;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
        $registrar->setPermissionsTeamId(null);

        // 1. Create the global permission in the catalogue
        $permission = Permission::firstOrCreate([
            'name' => 'deliver_orders',
            'guard_name' => 'web',
        ]);

        // 2. Grant to platform-level super_admin
        $superAdmin = Role::whereNull('tenant_id')
            ->where('name', RoleDefinitions::SUPER_ADMIN)
            ->first();
        $superAdmin?->givePermissionTo($permission);

        // 3. For all existing tenants entitled to the delivery module, grant to rider and admin roles
        $context = app(TenantContext::class);
        Tenant::withTrashed()->with('package')->get()->each(function (Tenant $tenant) use ($context, $permission) {
            if ($tenant->hasModule('delivery')) {
                $context->runFor($tenant, function () use ($tenant, $permission) {
                    // restaurant_admin gets all entitled permissions
                    $adminRole = Role::where('tenant_id', $tenant->id)
                        ->where('name', RoleDefinitions::RESTAURANT_ADMIN)
                        ->first();
                    $adminRole?->givePermissionTo($permission);

                    // rider role gets deliver_orders
                    $riderRole = Role::where('tenant_id', $tenant->id)
                        ->where('name', 'rider')
                        ->first();
                    $riderRole?->givePermissionTo($permission);
                });
            }
        });

        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'deliver_orders')->delete();
    }
};
