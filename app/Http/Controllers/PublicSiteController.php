<?php

namespace App\Http\Controllers;

use App\Jobs\Venues\EmailVenueGuest;
use App\Models\Customer;
use App\Models\GoogleBusinessConnection;
use App\Models\GoogleReview;
use App\Models\Hall;
use App\Models\Location;
use App\Models\MetaConnection;
use App\Models\Reservation;
use App\Models\SiteIntegration;
use App\Models\SiteVersion;
use App\Models\SocialLink;
use App\Models\SocialPost;
use App\Models\Tenant;
use App\Models\VenueBooking;
use App\Rules\PhoneNumber;
use App\Support\Billing\Modules;
use App\Support\Branding\RestaurantBranding;
use App\Support\PhoneNumber as Phone;
use App\Support\Site\Conversion;
use App\Support\Site\SiteSections;
use App\Support\Site\SiteTheme;
use App\Support\Tenancy\TenantContext;
use App\Support\Venues\VenuePricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A restaurant's public website, as data - and the one thing it accepts back,
 * a reservation request.
 *
 * Public, tenant from the URL, like the online order link. Only the live
 * published version is served - pages, theme, header and footer together -
 * never the editor's drafts. A restaurant that has never published shows a
 * Coming soon page with its name and contact details. Menu, ordering and branches are
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
            $isHome = $slug === null || $slug === '' || $slug === 'home';
            $live = SiteVersion::live();

            // Never published: a Coming soon page, nothing of the drafts.
            if ($live === null) {
                abort_unless($isHome, 404, 'Page not found.');

                return response()->json($this->comingSoon($tenant, $branding));
            }

            $site = [
                'version' => $live->number,
                'theme' => SiteBuilderController::themeFrom($live->snapshot['theme'] ?? []),
                'pages' => array_values($live->snapshot['pages'] ?? []),
            ];
            $pages = collect($site['pages']);

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
                'coming_soon' => false,
                'restaurant' => $this->restaurant($tenant, $branding),
                'theme' => $theme,
                // The two fonts' categories and weights, to load and fall back.
                'fonts' => SiteTheme::fontFaces($theme),
                'version' => $site['version'],
                'tracking' => SiteIntegration::publicTags(),
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
                'review_summary' => in_array('reviews', $types, true) ? $this->reviewSummary() : null,
                'posts' => [
                    'facebook' => in_array('facebook_posts', $types, true) ? SocialPost::latest(SocialPost::FACEBOOK) : [],
                    'instagram' => in_array('instagram_feed', $types, true) ? SocialPost::latest(SocialPost::INSTAGRAM) : [],
                ],
                'social_accounts' => array_intersect($types, ['facebook_posts', 'instagram_feed']) ? MetaConnection::accountLinks() : null,
                'venues' => in_array('venues', $types, true) ? $this->venues() : [],
                'social' => in_array('social', $types, true) || $theme['footer_social'] ? $this->socialLinks() : [],
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function restaurant(Tenant $tenant, array $branding): array
    {
        return [
            'code' => $tenant->slug,
            'name' => $branding['name'] ?? $tenant->name,
            'logo_url' => $branding['logo_url'] ?? null,
            'phone' => $branding['phone'] ?? null,
            'email' => $branding['email'] ?? null,
            'address' => $branding['address'] ?? null,
            'currency' => $branding['currency'] ?? '৳',
        ];
    }

    /**
     * What a restaurant that has never published shows: its name, logo and
     * contact details on a Coming soon page - nothing from the editor's
     * drafts, and no tags. Online ordering works without a website, so the
     * page still points to it.
     *
     * @return array<string, mixed>
     */
    private function comingSoon(Tenant $tenant, array $branding): array
    {
        $theme = SiteBuilderController::defaultTheme();

        return [
            'coming_soon' => true,
            'restaurant' => $this->restaurant($tenant, $branding),
            'theme' => $theme,
            'fonts' => SiteTheme::fontFaces($theme),
            'version' => null,
            'tracking' => null,
            'pages' => [],
            'order_page' => null,
            'page' => [
                'title' => 'Coming soon',
                'kind' => null,
                'meta_title' => null,
                'meta_description' => null,
                'og_image' => null,
                'sections' => [],
            ],
            'reviews' => [],
            'review_summary' => null,
            'posts' => ['facebook' => [], 'instagram' => []],
            'social_accounts' => null,
            'social' => $this->socialLinks(),
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

            // By the stored (normalised) form - see PublicOrderController::customerFor.
            $customer = Customer::firstOrCreate(['phone' => Phone::normalise($validated['phone']) ?? $validated['phone']], ['name' => $validated['name']]);

            $reservation = Reservation::create([
                'customer_id' => $customer->id,
                'location_id' => $location->id,
                // The booking's own copy: the number staff call to confirm.
                'guest_name' => $validated['name'],
                'guest_phone' => Phone::normalise($validated['phone']) ?? $validated['phone'],
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

    /**
     * POST /site/{code}/venue-enquiries - an event enquiry from the website.
     *
     * Arrives in Venues as a tentative booking, source 'website', priced at
     * the venue's rent alone - the restaurant adds food and extras when it
     * gets back to the guest. Tentative bookings may overlap; whoever confirms
     * first holds the hall. The guest is kept informed by email.
     */
    public function venueEnquiry(Request $request, string $code): JsonResponse
    {
        $tenant = $this->siteTenant($code);

        if (! $tenant->hasModule(Modules::CRM)) {
            abort(404, 'This restaurant does not take venue bookings online.');
        }

        return $this->context->runFor($tenant, function () use ($request) {
            $v = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                // Everything about the booking is sent here - no phone or
                // WhatsApp verification. The phone is a contact number only.
                'email' => ['required', 'email:rfc', 'max:191'],
                'phone' => ['required', 'string', 'max:20', PhoneNumber::mobile()],
                'hall_id' => ['required', 'integer'],
                'event_type' => ['required', Rule::in(VenueBooking::EVENT_TYPES)],
                'date' => ['required', 'date_format:Y-m-d'],
                'start_time' => ['required', 'date_format:H:i'],
                'end_time' => ['required', 'date_format:H:i'],
                'guests' => ['required', 'integer', 'min:1', 'max:5000'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ] + Conversion::REQUEST_RULES);

            $hall = Hall::where('is_active', true)->whereKey($v['hall_id'])->first()
                ?? throw ValidationException::withMessages(['hall_id' => ['Choose one of our venues.']]);

            $tz = config('app.timezone');
            $start = Carbon::createFromFormat('Y-m-d H:i', $v['date'].' '.$v['start_time'], $tz);
            $end = Carbon::createFromFormat('Y-m-d H:i', $v['date'].' '.$v['end_time'], $tz);
            if ($end->lte($start)) {
                $end->addDay();
            }
            if ($start->isPast() || $start->gt(now()->addYear())) {
                throw ValidationException::withMessages(['date' => ['Choose a date from today up to a year ahead.']]);
            }

            $phone = Phone::normalise($v['phone']) ?? $v['phone'];
            $customer = Customer::firstOrCreate(['phone' => $phone], ['name' => $v['name']]);
            $price = VenuePricing::quote($hall, $start, $end, (int) $v['guests'], null, [], 0);

            $booking = VenueBooking::create([
                'location_id' => $hall->location_id,
                'hall_id' => $hall->id,
                'customer_id' => $customer->id,
                'guest_name' => $v['name'],
                'guest_phone' => $phone,
                'guest_email' => mb_strtolower(trim($v['email'])),
                'event_type' => $v['event_type'],
                'starts_at' => $start,
                'ends_at' => $end,
                'guests' => $v['guests'],
                'rent_amount' => $price['rent_amount'],
                'total' => $price['total'],
                'status' => VenueBooking::PENDING,
                'source' => 'website',
                'notes' => $v['notes'] ?? null,
            ]);

            PublicOrderController::reportConversion(Conversion::forVenueEnquiry($booking, $request));
            // "We have your enquiry" - and the address to reply to.
            EmailVenueGuest::notify($booking, 'received');

            return response()->json([
                'enquiry_id' => $booking->id,
                'venue' => $hall->name,
                'at' => $start->toIso8601String(),
                'guests' => $booking->guests,
            ], 201);
        });
    }

    /** @return list<array<string, mixed>> the venues taking bookings */
    private function venues(): array
    {
        return Hall::with('location:id,name')
            ->where('is_active', true)
            ->orderBy('location_id')->orderBy('name')
            ->get()
            ->map(fn (Hall $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'description' => $h->description,
                'capacity' => $h->capacity,
                'min_guests' => $h->min_guests,
                'price' => (float) $h->price,
                'rent_type' => $h->rent_type ?? Hall::PER_EVENT,
                'location' => $h->location?->name,
            ])
            ->values()
            ->all();
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
    /**
     * Reviews chosen for display, newest first - enough for a Reviews section
     * to sort and filter (5 stars only, newest, best...) as it is set to.
     */
    private function reviews(): array
    {
        return GoogleReview::where('is_displayed', true)
            ->orderByDesc('time')
            ->limit(60)
            ->get(['author_name', 'author_photo_url', 'rating', 'text', 'time', 'source'])
            ->map(fn (GoogleReview $r) => [
                'author' => $r->author_name,
                'photo' => $r->author_photo_url,
                'rating' => (int) $r->rating,
                'text' => $r->text,
                'time' => $r->time?->toIso8601String(),
                'source' => $r->source,
            ])
            ->values()
            ->all();
    }

    /**
     * The overall rating: Google's own figures when the profile is
     * connected, otherwise worked out from every review stored (shown or
     * not - hiding a review must not flatter the average).
     *
     * @return array{rating: float|null, total: int, write_review_url: string|null, google_url: string|null}
     */
    private function reviewSummary(): array
    {
        $google = GoogleBusinessConnection::first();

        if ($google?->average_rating !== null) {
            return [
                'rating' => round($google->average_rating, 1),
                'total' => (int) $google->total_reviews,
                'write_review_url' => $google->review_uri,
                'google_url' => $google->maps_uri,
            ];
        }

        $all = GoogleReview::whereNotNull('rating');

        return [
            'rating' => ($avg = $all->avg('rating')) !== null ? round((float) $avg, 1) : null,
            'total' => (clone $all)->count(),
            'write_review_url' => null,
            'google_url' => null,
        ];
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
