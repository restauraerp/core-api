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
     * Also checks that every row carries the same keys. A batch insert takes
     * its column list from the *first* row and binds the rest positionally, so
     * one row with an extra key fails as "Column count doesn't match value
     * count at row 8" - a message that names neither the column nor the
     * generator that added it. Two generators writing the same table (a routine
     * purchase order and a weekly restock that also sets `notes`) is all it
     * takes.
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

        unset($row);

        $this->assertUniformShape($rows);

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function assertUniformShape(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $expected = array_keys(reset($rows));
        sort($expected);

        foreach ($rows as $index => $row) {
            $keys = array_keys($row);
            sort($keys);

            if ($keys === $expected) {
                continue;
            }

            throw new RuntimeException(sprintf(
                '%s: row %d does not match the shape of the first row in this batch insert. Missing [%s], unexpected [%s].',
                static::class,
                $index,
                implode(', ', array_diff($expected, $keys)) ?: 'nothing',
                implode(', ', array_diff($keys, $expected)) ?: 'nothing',
            ));
        }
    }
}
