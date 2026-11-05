<?php

namespace App\Console\Commands;

use App\Models\MetaConnection;
use App\Models\Tenant;
use App\Support\Integrations\MetaPages;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Refreshes every connected restaurant's Facebook and Instagram posts - and
 * with them the image links, which Meta lets expire.
 */
class SyncSocialPosts extends Command
{
    protected $signature = 'site:sync-social-posts';

    protected $description = "Read each connected restaurant's latest Facebook and Instagram posts";

    public function handle(TenantContext $context): int
    {
        $tenantIds = MetaConnection::withoutGlobalScopes()->whereNotNull('page_id')->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $tenant = Tenant::find($tenantId);
            if ($tenant === null) {
                continue;
            }

            try {
                $read = $context->runFor($tenant, fn () => (new MetaPages(MetaConnection::firstOrFail()))->sync());
                $this->line("{$tenant->slug}: {$read['facebook']} Facebook, {$read['instagram']} Instagram");
            } catch (\Throwable $e) {
                $this->warn("{$tenant->slug}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
