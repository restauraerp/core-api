<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Database\Seeder;

/**
 * Demo content for the public demo box, reseeded nightly by the cron in
 * infra/templates/user_data.sh.tpl.
 *
 * Two tenants, not one. The demo is the only place tenant isolation is
 * continuously exercised against realistic data, and a scoping regression is
 * obvious here in a way it never is with a single tenant: the second
 * restaurant's orders start showing up in the first one's dashboard.
 *
 * The per-tenant seeders below are unchanged in spirit - they still use
 * Eloquent models, so running them inside TenantContext::runFor() is enough to
 * scope every read and stamp tenant_id on every write.
 */
class DemoSeeder extends Seeder
{
    /**
     * Seeders that build one restaurant's worth of demo data. Order matters:
     * locations before tables, products before orders.
     */
    private const PER_TENANT_SEEDERS = [
        LocationSeeder::class,
        WebsiteSeeder::class,
        InventoryItemSeeder::class,
        DemoUserSeeder::class,
        DemoEmployeeSeeder::class,
        ProductSeeder::class,
        CustomerSeeder::class,
        TableSeeder::class,
        AccountingSeeder::class,
        OrderSeeder::class,
        RecipeSeeder::class,
        DemoHrSeeder::class,
    ];

    public function run(): void
    {
        $tenants = [
            [
                'name' => 'Bangla Bistro',
                'slug' => config('app.demo_tenant_slug'),
                'plan' => 'cloud',
            ],
            [
                'name' => 'Spice Garden',
                'slug' => config('app.demo_tenant_secondary_slug'),
                'plan' => 'shared',
            ],
        ];

        $context = app(TenantContext::class);
        $provisioner = app(TenantProvisioner::class);

        foreach ($tenants as $attributes) {
            $tenant = Tenant::where('slug', $attributes['slug'])->first();

            if ($tenant === null) {
                $tenant = $provisioner->create($attributes + [
                    'status' => 'active',
                    'contact_email' => config('app.demo_username'),
                ]);
            } else {
                $provisioner->provision($tenant);
            }

            $this->command?->info("── Seeding demo tenant \"{$tenant->name}\" (code: {$tenant->slug}) ──");

            $context->runFor($tenant, function () {
                foreach (self::PER_TENANT_SEEDERS as $seeder) {
                    $this->call($seeder);
                }
            });
        }

        $this->command?->info('✅ DemoSeeder: '.count($tenants).' demo tenants seeded.');
    }
}
