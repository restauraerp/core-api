<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateTenant extends Command
{
    protected $signature = 'tenants:create
        {name : The restaurant name}
        {--slug= : URL-safe identifier used as the restaurant code and X-Tenant-ID value}
        {--plan=shared : shared|dedicated|cloud}
        {--owner-email= : Creates a restaurant_admin user with this email}
        {--owner-name= : Display name for the owner}
        {--owner-password= : Owner password (generated and printed if omitted)}
        {--owner-phone= : Owner phone number}';

    protected $description = 'Create a new tenant and populate it with the data a restaurant needs to start';

    public function handle(TenantProvisioner $provisioner): int
    {
        $plan = $this->option('plan');

        if (! array_key_exists($plan, Tenant::PLAN_OUTLET_LIMITS)) {
            $this->error("Unknown plan [{$plan}]. Expected one of: ".implode(', ', array_keys(Tenant::PLAN_OUTLET_LIMITS)));

            return self::FAILURE;
        }

        $ownerEmail = $this->option('owner-email');
        $owner = null;
        $generatedPassword = null;

        if ($ownerEmail) {
            $generatedPassword = $this->option('owner-password') ?: Str::password(16);

            $owner = [
                'email' => $ownerEmail,
                'name' => $this->option('owner-name'),
                'password' => $generatedPassword,
                'phone' => $this->option('owner-phone'),
            ];
        }

        $tenant = $provisioner->create([
            'name' => $this->argument('name'),
            'slug' => $this->option('slug'),
            'plan' => $plan,
            'status' => 'trialing',
            'contact_email' => $ownerEmail,
            'trial_ends_at' => now()->addDays(14),
        ], $owner);

        $this->info("Tenant #{$tenant->id} \"{$tenant->name}\" created.");
        $this->line("  Restaurant code (X-Tenant-ID): {$tenant->slug}");
        $this->line("  Plan: {$tenant->plan} (outlet limit: ".($tenant->max_outlets ?? 'unlimited').')');

        if ($owner !== null) {
            $this->line("  Owner: {$ownerEmail}");

            if (! $this->option('owner-password')) {
                $this->warn("  Generated password: {$generatedPassword}");
                $this->warn('  This is shown once. Store it now.');
            }
        }

        return self::SUCCESS;
    }
}
