<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * Gives existing restaurants the predefined roles added since they were
 * created - and only those.
 *
 * Safe on every deploy (the workflow runs it): a restaurant that already has
 * every role is untouched, and roles it has are never changed - their
 * permissions and sign-in setting are the restaurant's own. Compare
 * tenants:repair-roles, which re-syncs every role to its defaults.
 */
class SyncTenantRoles extends Command
{
    protected $signature = 'tenants:sync-roles
        {--tenant= : Only this restaurant (code or id)}
        {--dry-run : Report what would be added without adding it}';

    protected $description = 'Add newly defined predefined roles (e.g. waiter) to existing restaurants, leaving their current roles alone';

    public function handle(TenantProvisioner $provisioner): int
    {
        $only = $this->option('tenant');

        $tenants = Tenant::withTrashed()->with('package')
            ->when($only, fn ($q) => ctype_digit((string) $only) ? $q->whereKey((int) $only) : $q->where('slug', $only))
            ->get();

        if ($only && $tenants->isEmpty()) {
            $this->error("No restaurant matches [{$only}].");

            return self::FAILURE;
        }

        $defined = array_keys(RoleDefinitions::tenantRoles());
        $added = 0;

        foreach ($tenants as $tenant) {
            if ($this->option('dry-run')) {
                $have = Role::where('tenant_id', $tenant->getKey())->pluck('name')->all();
                $missing = array_values(array_diff($defined, $have));
            } else {
                $missing = $provisioner->addMissingRoles($tenant);
            }

            if ($missing !== []) {
                $added += count($missing);
                $this->line(sprintf('  %s [%s]: %s', $this->option('dry-run') ? 'would add' : 'added', $tenant->slug, implode(', ', $missing)));
            }
        }

        $this->info(($this->option('dry-run') ? 'Dry run: ' : '')."{$added} role(s) across {$tenants->count()} restaurant(s).");

        return self::SUCCESS;
    }
}
