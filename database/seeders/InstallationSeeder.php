<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Database\Seeder;

/**
 * Provisions the tenant a fresh install starts with.
 *
 * Before tenancy the app had exactly one implicit tenant and this seeder was a
 * placeholder. Now it has to create a real one: with tenant_id NOT NULL on
 * every table, nothing can be written until a tenant exists.
 */
class InstallationSeeder extends Seeder
{
    public function run(): void
    {
        $slug = config('app.install_tenant_slug');

        $tenant = Tenant::where('slug', $slug)->first();

        $provisioner = app(TenantProvisioner::class);

        if ($tenant === null) {
            $tenant = $provisioner->create([
                'name' => config('app.install_tenant_name'),
                'slug' => $slug,
                'plan' => 'enterprise',
                'status' => 'active',
                'contact_email' => config('app.admin_email'),
            ]);
        } else {
            // Top up anything a previous release did not provision.
            $provisioner->provision($tenant);
        }

        $this->command?->info("✅ InstallationSeeder: tenant #{$tenant->id} \"{$tenant->name}\" (code: {$tenant->slug}) ready.");
    }
}
