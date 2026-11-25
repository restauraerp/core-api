<?php

namespace App\Services;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;

class DeliveryPersonService
{
    public const CACHE_TTL_SECONDS = 3600; // 1 hour

    /**
     * Cache key for tenant's delivery persons list.
     */
    public static function cacheKey(int|string $tenantId): string
    {
        return "tenant:{$tenantId}:delivery_persons";
    }

    /**
     * Get cached delivery persons for the specified tenant (or current tenant).
     */
    public static function getForTenant(?int $tenantId = null): Collection
    {
        $tenantId ??= app(TenantContext::class)->id();
        if (! $tenantId) {
            return new Collection;
        }

        $key = self::cacheKey($tenantId);

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($tenantId) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

            return User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where(function ($q) {
                    $q->whereHas('roles', fn ($r) => $r->where('name', 'rider'))
                        ->orWhereHas('permissions', fn ($p) => $p->where('name', 'deliver_orders'))
                        ->orWhereHas('roles.permissions', fn ($p) => $p->where('name', 'deliver_orders'));
                })
                ->with(['roles:id,name', 'permissions:id,name', 'location:id,name'])
                ->select(['id', 'name', 'email', 'phone', 'location_id', 'tenant_id'])
                ->orderBy('name')
                ->get()
                ->filter(fn (User $user) => $user->can_deliver)
                ->values();
        });
    }

    /**
     * Flush cached delivery persons for a specific tenant.
     */
    public static function flushForTenant(int|string|null $tenantId = null): void
    {
        $tenantId ??= app(TenantContext::class)->id();
        if ($tenantId) {
            Cache::forget(self::cacheKey($tenantId));
        }
    }
}
