<?php

namespace App\Http\Controllers;

use App\Models\SitePage;
use App\Models\Tenant;
use App\Support\Billing\Modules;
use App\Support\Branding\RestaurantBranding;
use App\Support\Site\SiteSections;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * GET /site/{code}/{slug?} - a restaurant's public website, as data.
 *
 * Public, tenant from the URL, like the online order link. Only published
 * pages are served; a restaurant that has never published a home page gets
 * the starter page, built from what it already has, so every restaurant with
 * the Website module has a site from day one. Live sections (menu, ordering,
 * branches) carry no data here - the site reads that from order/{code} as it
 * renders, so it is never stale.
 */
class PublicSiteController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function show(string $code, ?string $slug = null): JsonResponse
    {
        $tenant = Tenant::where('slug', $code)->first();

        if ($tenant === null || ! $tenant->hasModule(Modules::WEBSITE)) {
            abort(404, 'This restaurant does not have a website.');
        }

        return $this->context->runFor($tenant, function () use ($tenant, $slug) {
            $branding = RestaurantBranding::current();
            $published = SitePage::whereNotNull('published')->orderBy('sort_order')->orderBy('id')->get();

            $page = $slug === null || $slug === '' || $slug === 'home'
                ? $published->firstWhere('is_home', true)
                : $published->firstWhere('slug', $slug);

            if ($page === null && $slug !== null && $slug !== '' && $slug !== 'home') {
                abort(404, 'Page not found.');
            }

            $name = $branding['name'] ?? $tenant->name;

            return response()->json([
                'restaurant' => [
                    'code' => $tenant->slug,
                    'name' => $name,
                    'logo_url' => $branding['logo_url'] ?? null,
                    'phone' => $branding['phone'] ?? null,
                    'email' => $branding['email'] ?? null,
                    'address' => $branding['address'] ?? null,
                    'currency' => $branding['currency'] ?? '৳',
                ],
                // Navigation: the published pages, home first.
                'pages' => $published->sortByDesc('is_home')->values()->map(fn (SitePage $p) => [
                    'slug' => $p->is_home ? '' : $p->slug,
                    'title' => $p->title,
                ]),
                'page' => [
                    'title' => $page?->title ?? 'Home',
                    'sections' => $page?->published ?? SiteSections::starterHome($name),
                ],
            ]);
        });
    }
}
