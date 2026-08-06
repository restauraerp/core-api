<?php

namespace App\Console\Commands;

use App\Support\Billing\Plans;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateTenant extends Command
{
    protected $signature = 'tenants:create
        {name : The restaurant name}
        {--slug= : URL-safe identifier used as the restaurant code and X-Tenant-ID value}
        {--plan=starter : starter|growth|business|enterprise}
        {--owner-email= : Creates a restaurant_admin user with this email}
        {--owner-name= : Display name for the owner}
        {--owner-password= : Owner password (generated and printed if omitted)}
        {--owner-phone= : Owner phone number}';

    protected $description = 'Create a new tenant and populate it with the data a restaurant needs to start [--slug= --plan= --owner-*]';

    protected $help = <<<'HELP'
        Creates the tenant and provisions it: roles, a head-office location, product
        categories, tags, starter website content and pages, loyalty settings and an
        inactive VAT rule. It starts on a 14-day trial.

        The slug is the restaurant code the owner types on the login form and the value
        sent in the X-Tenant-ID header. Omit it and one is derived from the name, with a
        numeric suffix if that slug is taken.

        Passing --owner-email also creates a restaurant_admin user. Without
        --owner-password a password is generated and printed once - copy it before the
        output scrolls away.

        Examples:
          <info>php artisan tenants:create "Bangla Bistro"</info>
            Slug derived from the name, shared plan, no owner user.

          <info>php artisan tenants:create "Bangla Bistro" --slug=bangla-bistro --plan=business</info>
            Explicit restaurant code, 5-outlet plan.

          <info>php artisan tenants:create "Dhaka Grill House" --owner-email=owner@dhakagrill.com</info>
            Creates the owner too, printing a generated password.

          <info>php artisan tenants:create "Dhaka Grill House" --plan=enterprise --owner-email=owner@dhakagrill.com --owner-name="Rahim Uddin" --owner-password='s3cret!' --owner-phone=+8801700000000</info>
            Everything set explicitly - unlimited outlets, no generated password.
        HELP;

    public function handle(TenantProvisioner $provisioner): int
    {
        $plan = $this->option('plan');

        if (! Plans::exists($plan)) {
            $this->error("Unknown plan [{$plan}]. Expected one of: ".implode(', ', Plans::tiers()));

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
            // Every new restaurant gets the same trial regardless of anything
            // else, from config/billing.php. A trial has no grace period: the
            // day it ends the tenant goes read-only.
            'trial_ends_at' => now()->addDays((int) config('billing.trial_days', 7)),
        ], $owner);

        $this->info("Tenant #{$tenant->id} \"{$tenant->name}\" created.");
        $this->line("  Restaurant code (X-Tenant-ID): {$tenant->slug}");
        $this->line("  Plan: {$tenant->plan} (outlet limit: ".($tenant->max_outlets ?? 'unlimited').')');
        $this->line("  Trial ends: {$tenant->trial_ends_at->toFormattedDateString()} (".config('billing.trial_days').' days, no grace period)');
        $this->line("  Subscribe with: php artisan tenants:subscribe {$tenant->slug} --monthly");

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
