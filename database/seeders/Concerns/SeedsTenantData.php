<?php

namespace Database\Seeders\Concerns;

use App\Support\Tenancy\TenantContext;
use RuntimeException;

/**
 * Helpers for seeders that reach past Eloquent.
 *
 * Seeders using models need none of this - BelongsToTenant stamps tenant_id on
 * create and scopes every read, provided the seeder runs inside
 * TenantContext::runFor(). But bulk `DB::table(...)->insert()` skips Eloquent
 * entirely, so those rows arrive with a NULL tenant_id and violate the NOT NULL
 * constraint.
 */
trait SeedsTenantData
{
    protected function tenantId(): int
    {
        $id = app(TenantContext::class)->id();

        if ($id === null) {
            throw new RuntimeException(
                static::class.' must run inside TenantContext::runFor() - no tenant is in context.'
            );
        }

        return $id;
    }

    /**
     * Stamp tenant_id onto rows headed for a raw query-builder insert.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function stampTenant(array $rows): array
    {
        $tenantId = $this->tenantId();

        foreach ($rows as &$row) {
            $row['tenant_id'] ??= $tenantId;
        }

        return $rows;
    }
}
