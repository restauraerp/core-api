<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Billing\Subscription;
use Illuminate\Console\Command;

/**
 * Suspends tenants whose trial or subscription has run out.
 *
 * Access is already denied the moment a date passes - Tenant::isActive() checks
 * it and ResolveTenant rejects the request - so this command is not the gate.
 * What it does is make `status` tell the truth: without it a lapsed account
 * reads "trialing" forever in tenants:list and in every report, while quietly
 * serving nobody.
 */
class ExpireTenants extends Command
{
    protected $signature = 'tenants:expire
        {--dry-run : List the tenants that would be suspended without changing them}';

    protected $description = 'Suspend tenants whose trial or subscription has lapsed [--dry-run]';

    protected $help = <<<'HELP'
        Moves tenants past their trial_ends_at (while trialing) or their
        subscription_ends_at (once active) to the suspended status.

        Access does not depend on this running: an expired tenant is refused at the
        middleware the moment the date passes. This keeps the status column honest
        so support and reporting see the same thing the API enforces.

        A tenant with no end date recorded is open-ended, not expired, and is left
        alone - otherwise every tenant created before billing dates were tracked
        would be locked out.

        Runs daily from the scheduler.

        Examples:
          <info>php artisan tenants:expire --dry-run</info>
          <info>php artisan tenants:expire</info>
        HELP;

    public function handle(): int
    {
        // Grace is applied per row rather than in SQL because its length
        // depends on the billing cycle, and a tenant still inside its window
        // must be left alone - it has full access, not merely a stale status.
        $lapsed = Tenant::query()
            ->whereIn('status', ['trialing', 'active'])
            ->where(function ($query) {
                $query->where(function ($trialing) {
                    $trialing->where('status', 'trialing')
                        ->whereNotNull('trial_ends_at')
                        ->where('trial_ends_at', '<', now());
                })->orWhere(function ($active) {
                    $active->where('status', 'active')
                        ->whereNotNull('subscription_ends_at')
                        ->where('subscription_ends_at', '<', now());
                });
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (Tenant $tenant) => $tenant->subscription()['state'] === Subscription::READ_ONLY)
            ->values();

        if ($lapsed->isEmpty()) {
            $this->info('No lapsed tenants.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Code', 'Was', 'Lapsed'],
            $lapsed->map(fn (Tenant $tenant) => [
                $tenant->id,
                $tenant->name,
                $tenant->slug,
                $tenant->status,
                ($tenant->status === 'trialing' ? $tenant->trial_ends_at : $tenant->subscription_ends_at)?->toDateString(),
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->comment('Dry run - '.$lapsed->count().' tenant(s) would be suspended.');

            return self::SUCCESS;
        }

        Tenant::whereIn('id', $lapsed->pluck('id'))->update(['status' => 'suspended']);

        $lapsed->each(fn (Tenant $tenant) => Subscription::forget($tenant));

        $this->info($lapsed->count().' tenant(s) suspended. They keep read access; only writes are refused.');

        return self::SUCCESS;
    }
}
