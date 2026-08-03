<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the current tenant.
 *
 * This is also what makes implicit route-model binding safe: `show(Product
 * $product)` resolves through this scope, so another tenant's id 404s instead
 * of returning their data.
 *
 * Behaviour when no tenant is set depends on where we are:
 *
 *   - HTTP: fail *closed*. ResolveTenant is supposed to guarantee a tenant on
 *     every API route, so no tenant here means a routing or middleware gap. In
 *     that case returning nothing is right and returning everything is a
 *     cross-tenant leak, so we scope to an impossible value rather than trust
 *     the middleware to be complete.
 *
 *   - Console: fail *open*. Seeders, migrations and maintenance commands
 *     legitimately work across tenants and carry no request identity to
 *     protect. They opt into a tenant with TenantContext::runFor().
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        $column = $model->qualifyColumn('tenant_id');

        if ($context->has()) {
            $builder->where($column, $context->id());

            return;
        }

        if (app()->runningInConsole()) {
            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
