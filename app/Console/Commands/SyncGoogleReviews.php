<?php

namespace App\Console\Commands;

use App\Models\GoogleBusinessConnection;
use App\Models\Tenant;
use App\Support\Integrations\GoogleBusiness;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Brings every connected restaurant's Google reviews and rating up to date. */
class SyncGoogleReviews extends Command
{
    protected $signature = 'site:sync-google-reviews';

    protected $description = "Read each connected restaurant's Google Business Profile reviews";

    public function handle(TenantContext $context): int
    {
        $tenantIds = GoogleBusinessConnection::withoutGlobalScopes()->whereNotNull('location')->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $tenant = Tenant::find($tenantId);
            if ($tenant === null) {
                continue;
            }

            try {
                $read = $context->runFor($tenant, fn () => (new GoogleBusiness(GoogleBusinessConnection::firstOrFail()))->sync());
                $this->line("{$tenant->slug}: {$read} reviews");
            } catch (\Throwable $e) {
                // Recorded on the connection for the owner to see; carry on with the rest.
                $this->warn("{$tenant->slug}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
