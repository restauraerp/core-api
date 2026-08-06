<?php

namespace App\Http\Middleware;

use App\Support\Billing\Plans;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses routes belonging to a module the tenant's plan does not include.
 *
 * This is a *plan* gate, deliberately separate from the permission system.
 * Permissions answer "may this user do it"; this answers "did this restaurant
 * buy it". Keeping them apart means entitlement can be enforced without
 * touching anybody's roles, and a Starter tenant cannot reach CRM by being
 * granted view_crm.
 *
 * It has to exist at all because the API carries no permission middleware -
 * permissions currently only drive which routes the front renders, so without
 * this a Starter customer could call the endpoints directly and get everything
 * Growth is sold for.
 *
 * Applied as `module:crm`, `module:hr`, ... in routes/api.php.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $tenant = app(TenantContext::class)->get();

        // No tenant in context means either a platform admin doing cross-tenant
        // work or a public storefront route. Neither is a subscription
        // question, and ResolveTenant has already rejected anything invalid.
        if ($tenant === null) {
            return $next($request);
        }

        if ($tenant->hasModule($module)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Your plan does not include this module.',
            'error' => 'module_not_in_plan',
            'module' => $module,
            'plan' => $tenant->plan,
            'plan_name' => $tenant->planLabel(),
            // What the caller has to move to. Cheapest tier that includes it,
            // so the client can name a concrete upgrade rather than "contact
            // sales".
            'upgrade_to' => $this->cheapestTierWith($module),
        ], Response::HTTP_FORBIDDEN);
    }

    /**
     * Tiers are declared cheapest-first in config/plans.php.
     */
    private function cheapestTierWith(string $module): ?string
    {
        foreach (Plans::tiers() as $tier) {
            if (Plans::includesModule($tier, $module)) {
                return $tier;
            }
        }

        return null;
    }
}
