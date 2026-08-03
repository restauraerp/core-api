<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The install path. Produces a working, empty-but-usable system.
 *
 * NOTE: this seeder deliberately does NOT use WithoutModelEvents. That trait
 * wraps the run in Model::withoutEvents(), which would suppress the `creating`
 * hook in BelongsToTenant - the hook that stamps tenant_id on every insert.
 * With tenant_id NOT NULL across 54 tables, seeding would fail outright.
 *
 * Catalogue/website/inventory content moved out of here: TenantProvisioner now
 * populates a new tenant's categories, tags and CMS defaults, and the demo
 * restaurant's branded content belongs to DemoSeeder.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Global permission catalogue + the platform-level super_admin role.
            RolePermissionSeeder::class,

            // First tenant, fully provisioned. Must run before anything that
            // writes tenant-owned rows.
            InstallationSeeder::class,

            // Platform operator (is_platform_admin).
            AdminUserSeeder::class,
        ]);
    }
}
