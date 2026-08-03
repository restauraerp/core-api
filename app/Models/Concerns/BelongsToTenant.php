<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Marks a model as tenant-owned: reads are scoped to the current tenant and
 * writes are stamped with it automatically.
 *
 * Applied to every domain model. `tenant_id` is intentionally NOT in any
 * model's $fillable - it is set here, so a client cannot reassign a record to
 * another tenant by putting tenant_id in the request body.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $tenantId = app(TenantContext::class)->id();

            if ($tenantId !== null) {
                $model->setAttribute('tenant_id', $tenantId);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Applies the tenant to a many-to-many relation whose pivot table carries
     * tenant_id.
     *
     * attach()/sync() write pivot rows through the query builder, so neither
     * the global scope nor the `creating` hook above ever sees them - a plain
     * sync() inserts a NULL tenant_id and fails the NOT NULL constraint.
     * withPivotValue() fixes both directions at once: it supplies the value on
     * write and constrains the relation on read.
     *
     * No-ops when no tenant is in context (console work outside runFor), where
     * pinning the pivot to NULL would be worse than leaving it alone.
     */
    protected function scopedToTenantPivot(BelongsToMany $relation): BelongsToMany
    {
        $tenantId = app(TenantContext::class)->id();

        return $tenantId === null
            ? $relation
            : $relation->withPivotValue('tenant_id', $tenantId);
    }

    /**
     * Escape hatch for platform-level queries. Named verbosely on purpose -
     * every call site should be obvious in review.
     */
    public function scopeAcrossAllTenants($query)
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
