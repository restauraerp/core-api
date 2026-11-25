<?php

namespace App\Services;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
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
     * Returns an Illuminate\Support\Collection of plain arrays to avoid serialization
     * issues (__PHP_Incomplete_Class) across deployments.
     */
    public static function getForTenant(?int $tenantId = null): Collection
    {
        $tenantId ??= app(TenantContext::class)->id();
        if (! $tenantId) {
            return collect();
        }

        $key = self::cacheKey($tenantId);

        // Defensive check: if cache has corrupt / incomplete class or invalid format, flush it
        if (Cache::has($key)) {
            $cached = Cache::get($key);
            $isCorrupt = $cached instanceof \__PHP_Incomplete_Class
                || (! is_array($cached) && ! ($cached instanceof Collection));

            if (! $isCorrupt && $cached instanceof Collection) {
                $isCorrupt = $cached->contains(fn ($item) => $item instanceof \__PHP_Incomplete_Class);
            }

            if ($isCorrupt) {
                Cache::forget($key);
            } else {
                return collect($cached)->map(fn ($item) => (array) $item);
            }
        }

        $data = self::queryForTenant($tenantId);

        Cache::put($key, $data, self::CACHE_TTL_SECONDS);

        return collect($data);
    }

    /**
     * Query and extract plain array data for delivery persons.
     */
    public static function queryForTenant(int|string $tenantId): array
    {
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
            ->values()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'location_id' => $user->location_id,
                'tenant_id' => $user->tenant_id,
                'can_deliver' => true,
                'roles' => $user->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values()->all(),
                'permissions' => $user->permissions->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
                'location' => $user->location ? ['id' => $user->location->id, 'name' => $user->location->name] : null,
            ])
            ->all();
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
