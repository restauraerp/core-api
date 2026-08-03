<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The platform operator - not a restaurant user.
 *
 * They are stored inside the install tenant because users.tenant_id is NOT
 * NULL, but is_platform_admin is what actually grants them reach: Gate::before
 * short-circuits every permission check, and ResolveTenant lets them target any
 * tenant with an X-Tenant-ID header.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', config('app.install_tenant_slug'))->first();

        if ($tenant === null) {
            $this->command?->warn('⚠️  AdminUserSeeder skipped: run InstallationSeeder first.');

            return;
        }

        app(TenantContext::class)->runFor($tenant, function () use ($tenant) {
            $user = User::firstOrNew([
                'email' => config('app.admin_email'),
                'tenant_id' => $tenant->getKey(),
            ]);

            $user->fill([
                'name' => 'Aftabul Islam',
                'location_id' => Location::query()->value('id'),
            ]);

            if (! $user->exists) {
                $user->password = Hash::make(config('app.admin_password'));
                $user->email_verified_at = now();
            }

            $user->tenant_id = $tenant->getKey();
            $user->is_platform_admin = true;
            $user->save();

            // The role row is global (tenant_id NULL); the assignment pivot is
            // not - model_has_roles.tenant_id is part of its primary key, so it
            // binds to whatever tenant is in context here.
            $user->assignRole(RoleDefinitions::SUPER_ADMIN);

            $this->command?->info("✅ AdminUserSeeder: platform admin {$user->email} ready.");
        });
    }
}
