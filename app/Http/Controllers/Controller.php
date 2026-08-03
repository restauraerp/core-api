<?php

namespace App\Http\Controllers;

use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

abstract class Controller
{
    /**
     * An `exists` rule confined to the current tenant.
     *
     * Plain `exists:locations,id` queries the table directly - it does not go
     * through Eloquent, so the BelongsToTenant global scope never applies. That
     * makes every unscoped exists rule a cross-tenant write vector: a request
     * could attach another restaurant's location, table, customer or discount
     * to its own order and the validator would wave it through.
     */
    protected function tenantExists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)
            ->where('tenant_id', app(TenantContext::class)->id());
    }

    /**
     * A `unique` rule confined to the current tenant, for the columns whose
     * uniqueness became per-tenant (slugs, emails, settings keys, ...).
     */
    protected function tenantUnique(string $table, string $column = 'NULL'): Unique
    {
        return Rule::unique($table, $column)
            ->where('tenant_id', app(TenantContext::class)->id());
    }
}
