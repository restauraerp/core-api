<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Repairs tenant roles that escaped their tier.
 *
 * Two faults, one cause. TenantProvisioner::createRoles() used to run happily
 * without a tenant in context: it wrote the whole set of tenant roles at
 * tenant_id NULL and, with no plan to read, granted them every permission
 * there is. Spatie counts a role with team_id NULL as global and resolves it
 * by name, so from then on assignRole('restaurant_admin') handed owners that
 * uncapped global role instead of their own restaurant's capped one - and a
 * Starter tenant's dashboard lit up CRM, HR, Delivery, Kiosk and Website.
 *
 * The provisioner now refuses to run without a tenant and assigns the role by
 * id, so no new rows like these can appear. This cleans up the ones that
 * already did:
 *
 *   - moves every user off a global tenant role onto their own tenant's copy
 *   - deletes the global tenant roles, keeping super_admin, which is
 *     legitimately platform-level
 *   - re-syncs every tenant's role permissions from its current tier
 */
class RepairTenantRoles extends Command
{
    protected $signature = 'tenants:repair-roles
        {--dry-run : Report what would change without touching anything}';

    protected $description = 'Re-point owners at their own tenant roles, drop stray global ones, and re-sync permissions to each tier [--dry-run]';

    protected $help = <<<'HELP'
        Safe to re-run: on a healthy install it reports nothing to do.

        Nothing is deleted except role rows that should never have existed - the
        tenant-level roles written at tenant_id NULL. super_admin is left alone.
        Users are re-pointed, never dropped, so nobody loses their login.

        Examples:
          <info>php artisan tenants:repair-roles --dry-run</info>
          <info>php artisan tenants:repair-roles</info>
        HELP;

    public function handle(TenantProvisioner $provisioner, TenantContext $context): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantRoleNames = array_keys(RoleDefinitions::tenantRoles());

        // Tenant roles living outside any tenant. super_admin is excluded by
        // construction: it is not in tenantRoles().
        $strays = Role::whereNull('tenant_id')
            ->whereIn('name', $tenantRoleNames)
            ->get();

        if ($strays->isEmpty()) {
            $this->info('No global tenant roles found.');
        }

        $moved = 0;
        $removed = 0;

        foreach ($strays as $stray) {
            $holders = DB::table('model_has_roles')
                ->where('role_id', $stray->getKey())
                ->get();

            // A live user we cannot re-point must keep the role they have.
            // Deleting it underneath them would strip their access to make the
            // report look tidy, which is the opposite of a repair.
            $stranded = 0;

            foreach ($holders as $holder) {
                $user = User::find($holder->model_id);

                if ($user === null) {
                    // The user is gone; only the pivot row survives it. Deleting
                    // the role takes the row with it.
                    $this->line("  orphan assignment to deleted user #{$holder->model_id} - will be cleaned up");

                    continue;
                }

                if ($user->tenant_id === null) {
                    $this->warn("  {$user->email} holds global [{$stray->name}] but belongs to no tenant - left alone");
                    $stranded++;

                    continue;
                }

                $own = Role::where('name', $stray->name)
                    ->where('guard_name', $stray->guard_name)
                    ->where('tenant_id', $user->tenant_id)
                    ->first();

                if ($own === null) {
                    $this->warn("  tenant #{$user->tenant_id} has no [{$stray->name}] of its own - left alone");
                    $stranded++;

                    continue;
                }

                $this->line("  {$user->email}: global [{$stray->name}] #{$stray->getKey()} -> tenant #{$user->tenant_id} role #{$own->getKey()}");

                if (! $dryRun) {
                    DB::table('model_has_roles')
                        ->where('role_id', $stray->getKey())
                        ->where('model_id', $user->getKey())
                        ->where('model_type', $holder->model_type)
                        ->update(['role_id' => $own->getKey()]);
                }

                $moved++;
            }

            if ($stranded > 0) {
                $this->warn("  keeping global role [{$stray->name}] #{$stray->getKey()}: {$stranded} user(s) still need it");

                continue;
            }

            $this->line("  drop global role [{$stray->name}] #{$stray->getKey()}");

            if (! $dryRun) {
                DB::table('role_has_permissions')->where('role_id', $stray->getKey())->delete();
                DB::table('model_has_roles')->where('role_id', $stray->getKey())->delete();
                Role::whereKey($stray->getKey())->delete();
            }

            $removed++;
        }

        // Whatever the roles held before, they should hold what the tier says
        // now. This is also the half that fixes a tenant whose plan changed
        // through a path that forgot to re-sync.
        $resynced = 0;

        foreach (Tenant::all() as $tenant) {
            $this->line("  re-sync roles for [{$tenant->slug}] (plan {$tenant->plan})");

            if (! $dryRun) {
                $context->runFor($tenant, fn () => $provisioner->createRoles($tenant));
            }

            $resynced++;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->newLine();
        $this->info(sprintf(
            '%s %d user(s) re-pointed, %d stray role(s) removed, %d tenant(s) re-synced.',
            $dryRun ? 'Would have:' : 'Done:',
            $moved,
            $removed,
            $resynced,
        ));

        return self::SUCCESS;
    }
}
