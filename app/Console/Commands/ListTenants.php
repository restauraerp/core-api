<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

class ListTenants extends Command
{
    protected $signature = 'tenants:list
        {--status= : Only show tenants with this status (trialing|active|suspended|cancelled)}
        {--plan= : Only show tenants on this plan (shared|dedicated|cloud)}
        {--with-trashed : Include soft-deleted tenants}
        {--json : Output raw JSON instead of a table}';

    protected $description = 'List all existing tenants [--status= --plan= --with-trashed --json]';

    protected $help = <<<'HELP'
        Prints one row per tenant: ID, name, restaurant code (the X-Tenant-ID header
        value), plan, status, outlets used against the plan cap, user count, contact
        email and trial end date.

        Examples:
          <info>php artisan tenants:list</info>
          <info>php artisan tenants:list --status=trialing</info>
          <info>php artisan tenants:list --plan=cloud --with-trashed</info>
          <info>php artisan tenants:list --json</info>
        HELP;

    private const STATUSES = ['trialing', 'active', 'suspended', 'cancelled'];

    public function handle(): int
    {
        $status = $this->option('status');
        $plan = $this->option('plan');

        if ($status !== null && ! in_array($status, self::STATUSES, true)) {
            $this->error("Unknown status [{$status}]. Expected one of: ".implode(', ', self::STATUSES));

            return self::FAILURE;
        }

        if ($plan !== null && ! array_key_exists($plan, Tenant::PLAN_OUTLET_LIMITS)) {
            $this->error("Unknown plan [{$plan}]. Expected one of: ".implode(', ', array_keys(Tenant::PLAN_OUTLET_LIMITS)));

            return self::FAILURE;
        }

        $tenants = Tenant::query()
            ->withCount(['users', 'locations'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($plan, fn ($query) => $query->where('plan', $plan))
            ->when($this->option('with-trashed'), fn ($query) => $query->withTrashed())
            ->orderBy('id')
            ->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line($tenants->toJson(JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Code (X-Tenant-ID)', 'Plan', 'Status', 'Outlets', 'Users', 'Contact', 'Trial ends'],
            $tenants->map(fn (Tenant $tenant) => [
                $tenant->id,
                $tenant->trashed() ? "{$tenant->name} (deleted)" : $tenant->name,
                $tenant->slug,
                $tenant->plan,
                $tenant->status,
                $tenant->locations_count.'/'.($tenant->max_outlets ?? '∞'),
                $tenant->users_count,
                $tenant->contact_email ?? '-',
                $tenant->trial_ends_at?->toDateString() ?? '-',
            ])->all(),
        );

        $this->line('  '.$tenants->count().' tenant(s).');

        return self::SUCCESS;
    }
}
