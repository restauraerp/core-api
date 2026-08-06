<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Database\Seeder;

/**
 * Demo content for the public demo box, rebuilt by `demo:refresh` - nightly
 * from the cron in infra/templates/user_data.sh.tpl, and on every deploy.
 *
 * One tenant, not two. This used to build a second restaurant ("Spice Garden")
 * on the argument that a scoping regression would show up as one restaurant's
 * orders appearing in the other's dashboard. That check now lives in
 * tests/Feature/TenantIsolationTest.php, where it fails a build instead of
 * waiting for someone to notice a wrong number on a demo dashboard. What was
 * left was a second fictional restaurant that confused visitors and doubled a
 * reseed that already inserts two years of orders.
 *
 * The per-tenant seeders below use Eloquent models, so running them inside
 * TenantContext::runFor() is enough to scope every read and stamp tenant_id on
 * every write.
 *
 * Not idempotent, by way of OrderSeeder - seeding a tenant that already has
 * orders adds a second set rather than updating them. `demo:refresh` deletes
 * the tenant before calling this, which is the supported way to re-run it.
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
        $attributes = [
            'name' => 'Bangla Bistro',
            'slug' => config('app.demo_tenant_slug'),
            'plan' => 'enterprise',
        ];

        $context = app(TenantContext::class);
        $provisioner = app(TenantProvisioner::class);

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

        $this->command?->info("✅ DemoSeeder: \"{$tenant->name}\" (code: {$tenant->slug}) seeded.");
    }
}
