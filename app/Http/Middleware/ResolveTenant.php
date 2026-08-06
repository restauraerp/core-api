<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Billing\SubscriptionNotice;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes which tenant a request belongs to, before any controller runs.
 *
 * Resolution order matters, and is a security boundary:
 *
 *   1. Authenticated user -> users.tenant_id. Authoritative. A client-supplied
 *      header can never override it, because a header is attacker-controlled
 *      and would otherwise be a one-line cross-tenant read of every restaurant
 *      on the platform.
 *
 *   2. X-Tenant-ID header -> only for unauthenticated traffic (the public
 *      storefront: menu, categories, booking, POST storefront/orders), which
 *      carries no token to derive a tenant from.
 *
 *   3. Host -> matched against tenants.domain. Nothing populates that column
 *      yet; it is wired up so subdomain routing can be enabled later without
 *      touching this class or the schema.
 *
 * Platform admins are the single exception: they may target any tenant via the
 * header, which is what makes cross-tenant support work.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);

        // Explicitly the sanctum guard, not $request->user(): the default guard
        // is `web` (session), and this middleware runs in the api group before
        // the route's own auth:sanctum, so $request->user() would be null for
        // every token-authenticated request and silently fall through to the
        // header path below - handing an attacker their forged tenant.
        //
        // Scoping must be off for this one call. Authentication is a
        // chicken-and-egg: Sanctum has to load the User behind the token before
        // we can know the tenant, but User carries the tenant global scope,
        // which at this point has no tenant and therefore matches nothing. Left
        // scoped, every token in the system fails to authenticate.
        //
        // RequestGuard memoises the resolved user, so the later auth:sanctum on
        // the route reuses this result rather than re-querying.
        $user = $context->runWithoutScoping(
            fn () => Auth::guard('sanctum')->user()
        );

        if ($user !== null) {
            $tenant = $this->resolveForUser($request, $user, $context);

            if ($tenant instanceof Response) {
                return $tenant;
            }
        } else {
            $tenant = $this->resolveFromRequest($request);
        }

        if ($tenant === null) {
            // On a route that requires authentication, an anonymous caller is
            // simply unauthenticated - say so, and let auth:sanctum produce the
            // 401. Answering "no tenant" here would both leak that tenant
            // resolution runs first and give the wrong status for the actual
            // problem.
            if ($this->routeRequiresAuthentication($request)) {
                return $next($request);
            }

            return response()->json([
                'message' => 'Unable to determine the tenant for this request. Send an X-Tenant-ID header.',
            ], 400);
        }

        // Only a cancelled account is refused outright. An expired trial, a
        // lapsed subscription and a suspended account all stay readable - they
        // log in, see their orders and settings, and are stopped at the point
        // of writing by EnforceSubscription. Locking a restaurant out of its
        // own history over a late invoice costs more goodwill than the unpaid
        // week is worth.
        if ($tenant->isBlocked()) {
            return response()->json(SubscriptionNotice::blocked($tenant), 403);
        }

        $context->set($tenant);

        return $next($request);
    }

    /**
     * @return Tenant|Response|null
     */
    private function resolveForUser(Request $request, $user, TenantContext $context)
    {
        $requested = $this->requestedTenant($request);

        if ($user->isPlatformAdmin()) {
            // Cross-tenant reach: honour the header, fall back to their own.
            $context->bypass(false);

            return $requested ?? $user->tenant;
        }

        $own = $user->tenant;

        // A mismatched header is a probe, not a typo - refuse rather than
        // silently serving the user's own tenant.
        if ($requested !== null && $own !== null && $requested->getKey() !== $own->getKey()) {
            return response()->json([
                'message' => 'The X-Tenant-ID header does not match your account.',
            ], 403);
        }

        return $own;
    }

    private function resolveFromRequest(Request $request): ?Tenant
    {
        return $this->requestedTenant($request) ?? $this->resolveFromHost($request);
    }

    /**
     * Accepts either a numeric id or a slug, so the header and the login form's
     * "restaurant code" can carry the same value.
     */
    private function requestedTenant(Request $request): ?Tenant
    {
        $value = $request->header('X-Tenant-ID') ?: $request->input('tenant');

        if (blank($value)) {
            return null;
        }

        $query = Tenant::query();

        return is_numeric($value)
            ? $query->find((int) $value)
            : $query->where('slug', $value)->first();
    }

    private function resolveFromHost(Request $request): ?Tenant
    {
        return Tenant::where('domain', $request->getHost())->first();
    }

    /**
     * Whether the matched route is behind an auth middleware, in which case an
     * unresolvable tenant is really just a missing login.
     */
    private function routeRequiresAuthentication(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'auth')) {
                return true;
            }
        }

        return false;
    }
}
