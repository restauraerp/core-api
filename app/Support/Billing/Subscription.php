<?php

namespace App\Support\Billing;

use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What a tenant's billing state entitles it to do right now.
 *
 * Four states, and the difference between the middle two is the whole point:
 *
 *   FULL      - trial or subscription still running.
 *   GRACE     - a paid subscription has ended but its grace window has not.
 *               Behaves exactly like FULL; it exists so a late renewal does not
 *               stop a restaurant mid-service, and so the response can say how
 *               long is left.
 *   READ_ONLY - trial ended (immediately, no grace), or grace ran out, or the
 *               account was suspended. Everything is still readable; nothing
 *               can be written. A restaurant locked out of its own order
 *               history because an invoice is late is a worse outcome than an
 *               unpaid week.
 *   BLOCKED   - cancelled. The relationship is over; nothing is served.
 *
 * Resolved on every request, so it is cached in Redis rather than recomputed
 * from dates each time.
 */
class Subscription
{
    public const FULL = 'full';

    public const GRACE = 'grace';

    public const READ_ONLY = 'read_only';

    public const BLOCKED = 'blocked';

    /**
     * @return array{state: string, reason: ?string, expires_at: ?CarbonInterface, grace_ends_at: ?CarbonInterface, cycle: ?string}
     */
    public static function for(Tenant $tenant): array
    {
        $key = self::cacheKey($tenant->getKey());

        $cached = self::cache()->get($key);

        if (is_array($cached)) {
            return self::hydrate($cached);
        }

        // Re-read the tenant before deciding. The caller's instance may have
        // been loaded earlier in the request (Sanctum hands back a memoised
        // user, whose `tenant` relation is loaded once), and a stale model here
        // does not merely answer wrongly - it writes the wrong answer into the
        // cache for everyone. One query, and only on a miss.
        $resolved = self::resolve(Tenant::withTrashed()->find($tenant->getKey()) ?? $tenant);

        self::cache()->put($key, self::dehydrate($resolved), self::ttlFor($resolved));

        return $resolved;
    }

    /**
     * Computes the state from the tenant's own columns, ignoring the cache.
     *
     * @return array{state: string, reason: ?string, expires_at: ?CarbonInterface, grace_ends_at: ?CarbonInterface, cycle: ?string}
     */
    public static function resolve(Tenant $tenant): array
    {
        $cycle = $tenant->billing_cycle;

        if ($tenant->status === 'cancelled') {
            return self::state(self::BLOCKED, 'cancelled', null, null, $cycle);
        }

        // Suspended is an explicit decision - by support, or by the nightly
        // tenants:expire sweep once grace has run out. Either way the dates
        // below no longer decide anything.
        if ($tenant->status === 'suspended') {
            return self::state(self::READ_ONLY, 'suspended', null, null, $cycle);
        }

        if ($tenant->status === 'trialing') {
            $endsAt = $tenant->trial_ends_at;

            if ($endsAt === null || $endsAt->isFuture()) {
                return self::state(self::FULL, null, $endsAt, null, $cycle);
            }

            // No grace on a trial: nothing has been paid, so there is no late
            // payment to be patient about.
            return self::state(self::READ_ONLY, 'trial_expired', $endsAt, null, $cycle);
        }

        $endsAt = $tenant->subscription_ends_at;

        // NULL is an open-ended account, not an expired one - every tenant
        // predating billing dates would otherwise be locked out.
        if ($endsAt === null || $endsAt->isFuture()) {
            return self::state(self::FULL, null, $endsAt, null, $cycle);
        }

        $graceEndsAt = $endsAt->copy()->addDays(self::graceDays($cycle));

        if ($graceEndsAt->isFuture()) {
            return self::state(self::GRACE, 'in_grace', $endsAt, $graceEndsAt, $cycle);
        }

        return self::state(self::READ_ONLY, 'subscription_expired', $endsAt, $graceEndsAt, $cycle);
    }

    public static function graceDays(?string $cycle): int
    {
        return (int) config("billing.grace_days.{$cycle}", 0);
    }

    /**
     * Drops a tenant's cached state. Called by every command that changes
     * billing - without it a renewal would not take effect until the TTL ran
     * out, which is exactly the moment a customer is watching.
     */
    public static function forget(Tenant|int $tenant): void
    {
        self::cache()->forget(self::cacheKey($tenant instanceof Tenant ? $tenant->getKey() : $tenant));
    }

    private static function cache(): Repository
    {
        return Cache::store(config('billing.cache.store'));
    }

    private static function cacheKey(int|string $tenantId): string
    {
        return config('billing.cache.prefix').":{$tenantId}";
    }

    /**
     * Never cache past the next transition.
     *
     * A tenant one minute away from its grace ending must not keep full access
     * for the rest of the TTL, so the entry is capped at the moment the answer
     * would change.
     */
    private static function ttlFor(array $resolved): int
    {
        $ceiling = (int) config('billing.cache.ttl', 900);

        $next = $resolved['grace_ends_at'] ?? $resolved['expires_at'];

        if ($next === null || $next->isPast()) {
            return $ceiling;
        }

        return max(60, min($ceiling, (int) now()->diffInSeconds($next, absolute: true)));
    }

    private static function state(
        string $state,
        ?string $reason,
        ?CarbonInterface $expiresAt,
        ?CarbonInterface $graceEndsAt,
        ?string $cycle,
    ): array {
        return [
            'state' => $state,
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'grace_ends_at' => $graceEndsAt,
            'cycle' => $cycle,
        ];
    }

    /**
     * Carbon instances do not survive a cache round trip usefully, so dates go
     * in as ISO strings.
     */
    private static function dehydrate(array $resolved): array
    {
        return [
            ...$resolved,
            'expires_at' => $resolved['expires_at']?->toIso8601String(),
            'grace_ends_at' => $resolved['grace_ends_at']?->toIso8601String(),
        ];
    }

    private static function hydrate(array $cached): array
    {
        return [
            ...$cached,
            'expires_at' => $cached['expires_at'] === null ? null : Carbon::parse($cached['expires_at']),
            'grace_ends_at' => $cached['grace_ends_at'] === null ? null : Carbon::parse($cached['grace_ends_at']),
        ];
    }
}
