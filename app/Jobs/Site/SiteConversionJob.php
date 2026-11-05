<?php

namespace App\Jobs\Site;

use App\Models\SiteIntegration;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reports a website conversion (see App\Support\Site\Conversion) to one ad
 * platform, on the queue - a slow or failing platform must never hold up a
 * guest's order. Retried a few times with backoff; a conversion that still
 * cannot be sent is logged as a failed job, never a failed order.
 *
 * Reads the restaurant's integration when it runs, so a token removed in the
 * meantime stops the send.
 */
abstract class SiteConversionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @param  array<string, mixed>  $conversion */
    public function __construct(public int $tenantId, public array $conversion) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(TenantContext $context): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $context->runFor($tenant, function () {
            $integration = SiteIntegration::current();

            if ($integration !== null) {
                $this->send($integration);
            }
        });
    }

    abstract protected function send(SiteIntegration $integration): void;
}
