<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Billing\Plans;
use App\Support\Billing\Subscription;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;

/**
 * Moves a tenant between tiers.
 *
 * Changing `plan` alone is not enough and is the reason this exists: the outlet
 * cap is stored per tenant, and role permissions are synced from the tier at
 * provisioning time. Editing the column by hand leaves a restaurant that has
 * paid for Growth with Starter's permissions and Starter's cap.
 */
class ChangeTenantPlan extends Command
{
    protected $signature = 'tenants:plan
        {tenant : Tenant ID or slug}
        {plan : The tier to move to}
        {--keep-outlet-limit : Leave max_outlets alone instead of resetting it to the tier default}
        {--dry-run : Report the change without applying it}';

    protected $description = 'Change a tenant\'s subscription tier and re-sync its limits and permissions [--keep-outlet-limit --dry-run]';

    protected $help = <<<'HELP'
        Applies a tier change end to end: the plan column, the outlet cap, and the
        permissions on every role in the tenant.

        Downgrades never delete data. A restaurant that drops to a tier with fewer
        outlets keeps the ones it has - the cap only refuses the next one - and its
        CRM or HR records stay in the database, simply out of reach until it
        upgrades again.

        Use --keep-outlet-limit to preserve a support exception (a tenant granted
        more outlets than its tier normally allows).

        Examples:
          <info>php artisan tenants:plan acme-bistro growth --dry-run</info>
          <info>php artisan tenants:plan acme-bistro growth</info>
          <info>php artisan tenants:plan 7 enterprise</info>
        HELP;

    public function handle(TenantProvisioner $provisioner, TenantContext $context): int
    {
        $identifier = (string) $this->argument('tenant');
        $plan = (string) $this->argument('plan');

        if (! Plans::exists($plan)) {
            $this->error("Unknown plan [{$plan}]. Expected one of: ".implode(', ', Plans::tiers()));

            return self::FAILURE;
        }

        $tenant = Tenant::withTrashed()
            ->when(
                ctype_digit($identifier),
                fn ($query) => $query->where('id', (int) $identifier),
                fn ($query) => $query->where('slug', $identifier),
            )
            ->first();

        if ($tenant === null) {
            $this->error("No tenant matches [{$identifier}].");

            return self::FAILURE;
        }

        $from = (string) $tenant->plan;
        $newLimit = $this->option('keep-outlet-limit')
            ? $tenant->max_outlets
            : Plans::outletLimit($plan);

        $outlets = $tenant->locations()->count();

        $this->line('');
        $this->line("Tenant #{$tenant->id} \"{$tenant->name}\" (code: {$tenant->slug})");
        $this->table(['', 'From', 'To'], [
            ['Plan', $from, $plan],
            ['Outlet cap', $tenant->max_outlets ?? 'unlimited', $newLimit ?? 'unlimited'],
            ['Modules', count(Plans::exists($from) ? Plans::modules($from) : []), count(Plans::modules($plan))],
        ]);

        if ($newLimit !== null && $outlets > $newLimit) {
            $this->warn("  This tenant has {$outlets} outlets, above the new cap of {$newLimit}.");
            $this->line('  Existing outlets keep working; creating another will be refused.');
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run - nothing was changed.');

            return self::SUCCESS;
        }

        $tenant->plan = $plan;
        $tenant->max_outlets = $newLimit;
        $tenant->save();

        // Roles are per tenant and their permissions come from the tier, so the
        // change is not complete until they are re-synced.
        $context->runFor($tenant, fn () => $provisioner->createRoles($tenant));

        Subscription::forget($tenant);

        $this->info("Moved to {$plan}. Role permissions re-synced.");

        return self::SUCCESS;
    }
}
