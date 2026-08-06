<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Billing\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Starts or renews a paid subscription.
 *
 * The one command for every paid transition: converting a trial, renewing
 * before expiry, renewing after expiry, and reviving a tenant the nightly sweep
 * has already suspended.
 *
 * Renewing early never costs the customer days. The new period is measured from
 * whichever is later - today, or the end date they already have - so paying a
 * week early buys a month from the old end date, not from today.
 */
class SubscribeTenant extends Command
{
    protected $signature = 'tenants:subscribe
        {tenant : Tenant ID or slug}
        {--monthly : Bill monthly - extends by one month}
        {--yearly : Bill yearly - extends by one year}
        {--until= : Explicit end date (YYYY-MM-DD) instead of a cycle length}
        {--dry-run : Report the change without applying it}';

    protected $description = 'Start or renew a tenant\'s paid subscription [--monthly --yearly --until= --dry-run]';

    protected $help = <<<'HELP'
        Sets the tenant active, records the billing cycle, and moves
        subscription_ends_at forward.

        Renewing early does not lose paid days: the new period runs from the later of
        today and the current end date. Renewing after expiry runs from today, since
        the old period is already gone.

        Works from any state - trialing, active, expired, or suspended after the
        nightly sweep. A suspended tenant is set back to active by subscribing.

        Grace periods after expiry come from the cycle recorded here: 7 days on
        monthly, 14 on yearly (config/billing.php).

        Examples:
          <info>php artisan tenants:subscribe acme-bistro --monthly</info>
          <info>php artisan tenants:subscribe acme-bistro --yearly</info>
          <info>php artisan tenants:subscribe acme-bistro --yearly --dry-run</info>
          <info>php artisan tenants:subscribe 7 --until=2027-03-31</info>
        HELP;

    public function handle(): int
    {
        $identifier = (string) $this->argument('tenant');

        $cycle = match (true) {
            (bool) $this->option('yearly') => 'yearly',
            (bool) $this->option('monthly') => 'monthly',
            default => null,
        };

        if ($this->option('monthly') && $this->option('yearly')) {
            $this->error('Pass either --monthly or --yearly, not both.');

            return self::FAILURE;
        }

        if ($cycle === null && ! $this->option('until')) {
            $this->error('Specify a billing cycle: --monthly, --yearly, or --until=YYYY-MM-DD.');

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

        $endsAt = $this->newEndDate($tenant, $cycle);

        if ($endsAt === null) {
            return self::FAILURE;
        }

        $before = $tenant->subscription();

        $this->line('');
        $this->line("Tenant #{$tenant->id} \"{$tenant->name}\" (code: {$tenant->slug}, plan: {$tenant->planLabel()})");
        $this->table(['', 'From', 'To'], [
            ['Status', $tenant->status, 'active'],
            ['Billing cycle', $tenant->billing_cycle ?? '-', $cycle ?? $tenant->billing_cycle ?? '-'],
            ['Ends', $tenant->subscription_ends_at?->toDateString() ?? '-', $endsAt->toDateString()],
            ['Access', $before['state'], Subscription::FULL],
        ]);

        if ($tenant->subscription_ends_at?->isFuture()) {
            $this->line('  Renewing early - the new period runs from the existing end date, so no paid days are lost.');
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run - nothing was changed.');

            return self::SUCCESS;
        }

        $tenant->status = 'active';
        $tenant->billing_cycle = $cycle ?? $tenant->billing_cycle;
        $tenant->subscription_ends_at = $endsAt;
        $tenant->save();

        // Otherwise the customer keeps seeing the read-only notice until the
        // cached entry expires - at exactly the moment they have just paid.
        Subscription::forget($tenant);

        $graceDays = Subscription::graceDays($tenant->billing_cycle);

        $this->info("Subscribed until {$endsAt->toFormattedDateString()}.");
        $this->line("  Grace period after expiry: {$graceDays} day(s), then read-only.");

        return self::SUCCESS;
    }

    private function newEndDate(Tenant $tenant, ?string $cycle): ?Carbon
    {
        if ($until = $this->option('until')) {
            try {
                $date = Carbon::parse($until)->endOfDay();
            } catch (\Throwable) {
                $this->error("Could not read [{$until}] as a date. Use YYYY-MM-DD.");

                return null;
            }

            if ($date->isPast()) {
                $this->error("[{$until}] is in the past, which would expire the tenant immediately.");

                return null;
            }

            return $date;
        }

        // The later of today and the current end date: renewing early extends,
        // renewing late restarts.
        $from = $tenant->subscription_ends_at?->isFuture()
            ? $tenant->subscription_ends_at->copy()
            : now();

        return $cycle === 'yearly'
            ? $from->addYear()->endOfDay()
            : $from->addMonth()->endOfDay();
    }
}
