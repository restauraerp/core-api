<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\GoogleReview;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\SiteIntegration;
use App\Models\SiteVersion;
use App\Models\SocialLink;
use App\Models\Tenant;
use App\Rules\PhoneNumber;
use App\Support\Billing\Modules;
use App\Support\Branding\RestaurantBranding;
use App\Support\Site\Conversion;
use App\Support\Site\SiteDraft;
use App\Support\Site\SiteSections;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A restaurant's public website, as data - and the one thing it accepts back,
 * a reservation request.
 *
 * Public, tenant from the URL, like the online order link. Only the live
 * published version is served - pages, theme, header and footer together -
 * never the editor's drafts. A restaurant that has never published gets the
 * standard pages drafted from what it already has, so every restaurant with
 * the Website module has a site from day one. Menu, ordering and branches are
 * not included here - the site reads them from order/{code} as it renders.
 */
class PublicSiteController extends Controller
{
    public function __construct(private TenantContext $context) {}

    /** GET /site/{code}/{slug?} */
    public function show(string $code, ?string $slug = null): JsonResponse
    {
        $tenant = $this->siteTenant($code);

        return $this->context->runFor($tenant, function () use ($tenant, $slug) {
            $branding = RestaurantBranding::current();
            $site = $this->liveSite($tenant);
            $pages = collect($site['pages']);

            $isHome = $slug === null || $slug === '' || $slug === 'home';
            $page = $isHome ? ($pages->firstWhere('is_home', true) ?? $pages->first()) : $pages->firstWhere('slug', $slug);

            if ($page === null) {
                abort(404, 'Page not found.');
            }

            // A component the restaurant's package no longer includes is left
            // out rather than shown broken.
            $allowed = SiteSections::definitions($tenant->modules());
            $visible = fn (array $sections) => array_values(array_filter($sections, fn (array $section) => isset($allowed[$section['type']])));

            $sections = $visible($page['sections'] ?? []);
            $types = array_column($sections, 'type');
            $theme = $site['theme'];
            // Where "Order" buttons go: the site's own ordering page, if it has one.
            $orderPage = $pages->first(fn (array $p) => in_array('online_order', array_column($visible($p['sections'] ?? []), 'type'), true));

            return response()->json([
                'restaurant' => [
                    'code' => $tenant->slug,
                    'name' => $branding['name'] ?? $tenant->name,
                    'logo_url' => $branding['logo_url'] ?? null,
                    'phone' => $branding['phone'] ?? null,
                    'email' => $branding['email'] ?? null,
                    'address' => $branding['address'] ?? null,
                    'currency' => $branding['currency'] ?? '৳',
                ],
                'theme' => $theme,
                'version' => $site['version'],
                'tracking' => SiteIntegration::current()?->publicTags(),
                // Navigation: the published pages, home first.
                'pages' => $pages->sortByDesc('is_home')->values()->map(fn (array $p) => [
                    'slug' => ! empty($p['is_home']) ? '' : $p['slug'],
                    'title' => $p['title'],
                    'kind' => $p['kind'] ?? null,
                ]),
                'order_page' => $orderPage ? (! empty($orderPage['is_home']) ? '' : $orderPage['slug']) : null,
                'page' => [
                    'title' => $page['title'],
                    'kind' => $page['kind'] ?? null,
                    'meta_title' => $page['meta_title'] ?? null,
                    'meta_description' => $page['meta_description'] ?? null,
                    'og_image' => $page['og_image'] ?? null,
                    'sections' => $sections,
                ],
                // Only what this page's sections draw on.
                'reviews' => in_array('reviews', $types, true) ? $this->reviews() : [],
                'social' => in_array('social', $types, true) || $theme['footer_social'] ? $this->socialLinks() : [],
            ]);
        });
    }

    /**
     * The live version, or for a restaurant that never published, the
     * standard pages drafted from its data - without photos, since copying
     * files on a visitor's request is not this endpoint's business, and so
     * without a gallery that would be empty.
     *
     * @return array{version: int|null, theme: array<string, mixed>, pages: list<array<string, mixed>>}
     */
    private function liveSite(Tenant $tenant): array
    {
        $live = SiteVersion::live();

        if ($live !== null) {
            return [
                'version' => $live->number,
                'theme' => SiteBuilderController::themeFrom($live->snapshot['theme'] ?? []),
                'pages' => array_values($live->snapshot['pages'] ?? []),
            ];
        }

        return [
            'version' => null,
            'theme' => SiteBuilderController::defaultTheme(),
            'pages' => array_values(array_filter(
                (new SiteDraft($tenant))->pages(withPhoto: false),
                fn (array $page) => $page['kind'] !== 'gallery',
            )),
        ];
    }

    /**
     * POST /site/{code}/reservations - a table request from the website.
     *
     * Arrives in CRM > Reservations as Pending, source 'website', for the
     * restaurant to confirm. The guest is matched to a customer by phone, as
     * online orders are.
     */
    public function reserve(Request $request, string $code): JsonResponse
    {
        $tenant = $this->siteTenant($code);

        if (! $tenant->hasModule(Modules::CRM)) {
            abort(404, 'This restaurant does not take reservations online.');
        }

        return $this->context->runFor($tenant, function () use ($request) {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'phone' => ['required', 'string', 'max:20', PhoneNumber::mobile()],
                'location_id' => ['required', 'integer'],
                'date' => ['required', 'date_format:Y-m-d'],
                'time' => ['required', 'date_format:H:i'],
                'guests' => ['required', 'integer', 'min:1', 'max:50'],
                'notes' => ['nullable', 'string', 'max:500'],
            ] + Conversion::REQUEST_RULES);

            $location = Location::where('is_active', true)->whereKey($validated['location_id'])->first()
                ?? throw ValidationException::withMessages(['location_id' => ['Choose one of our branches.']]);

            $at = Carbon::createFromFormat('Y-m-d H:i', $validated['date'].' '.$validated['time'], config('app.timezone'));

            if ($at->isPast() || $at->gt(now()->addDays(90))) {
                throw ValidationException::withMessages(['date' => ['Choose a time from now up to 90 days ahead.']]);
            }

            $customer = Customer::firstOrCreate(['phone' => $validated['phone']], ['name' => $validated['name']]);

            $reservation = Reservation::create([
                'customer_id' => $customer->id,
                'location_id' => $location->id,
                'reservation_date' => $at,
                'guest_count' => $validated['guests'],
                'status' => 'Pending',
                'source' => 'website',
                'notes' => $validated['notes'] ?? null,
            ]);

            PublicOrderController::reportConversion(Conversion::forReservation($reservation, $request, $validated['name'], $validated['phone']));

            return response()->json([
                'reservation_id' => $reservation->id,
                'location' => $location->name,
                'at' => $at->toIso8601String(),
                'guests' => $reservation->guest_count,
            ], 201);
        });
    }

    /** The restaurant, if it has a website. */
    private function siteTenant(string $code): Tenant
    {
        $tenant = Tenant::where('slug', $code)->first();

        if ($tenant === null || ! $tenant->hasModule(Modules::WEBSITE)) {
            abort(404, 'This restaurant does not have a website.');
        }

        return $tenant;
    }

    /** @return list<array<string, mixed>> */
    private function reviews(): array
    {
        return GoogleReview::where('is_displayed', true)
            ->orderByDesc('time')
            ->limit(12)
            ->get(['author_name', 'rating', 'text', 'time'])
            ->map(fn (GoogleReview $r) => [
                'author' => $r->author_name,
                'rating' => (int) $r->rating,
                'text' => $r->text,
            ])
            ->values()
            ->all();
    }

    /**
     * Active social links - web addresses only, so a link the owner saved as
     * something else can never become script on the site.
     *
     * @return list<array{platform: string, url: string}>
     */
    private function socialLinks(): array
    {
        return SocialLink::where('is_active', true)->get(['platform', 'url'])
            ->filter(fn (SocialLink $l) => is_string($l->url) && preg_match('#^https?://#i', $l->url))
            ->map(fn (SocialLink $l) => ['platform' => strtolower((string) $l->platform), 'url' => $l->url])
            ->values()
            ->all();
    }
}
