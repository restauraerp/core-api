<?php

namespace Database\Seeders;

use App\Support\Tenancy\RoleDefinitions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Installs the *global* half of the authorisation model:
 *
 *   - the permission catalogue, which Spatie keeps global even under teams
 *     (permission names map 1:1 to hardcoded frontend route guards)
 *   - the platform-level super_admin role, at tenant_id = NULL
 *
 * Per-tenant roles (restaurant_admin, branch_manager, ...) are NOT created
 * here - TenantProvisioner stamps them into each tenant as it is created.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        // Global roles live outside any tenant. Without this the role below
        // would inherit whatever team id happens to be set and quietly stop
        // being global.
        $registrar->setPermissionsTeamId(null);

        foreach (RoleDefinitions::permissions() as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $superAdmin = Role::firstOrCreate([
            'name' => RoleDefinitions::SUPER_ADMIN,
            'guard_name' => 'web',
            'tenant_id' => null,
        ]);

        $superAdmin->syncPermissions(Permission::all());

        $registrar->forgetCachedPermissions();
    }
}
