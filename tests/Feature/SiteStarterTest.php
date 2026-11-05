<?php

namespace Tests\Feature;

use App\Models\Hall;
use App\Models\Location;
use App\Models\Package;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\Modules;
use App\Support\Site\SiteSections;
use App\Support\Site\SiteStarters;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Whole starter websites: theme and pages, filled with the restaurant's data. */
class SiteStarterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Spice Garden', 'slug' => 'spice', 'plan' => 'growth', 'status' => 'active',
        ]);

        Sanctum::actingAs(app(TenantContext::class)->runFor($this->tenant, function () {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $user->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

            return $user;
        }));
        $this->withHeader('X-Tenant-ID', 'spice');
    }

    #[Test]
    public function there_are_at_least_ten_starters_and_each_is_a_valid_site(): void
    {
        $starters = $this->getJson('/api/v1/site-builder/starters')->assertOk()->json('starters');

        $this->assertGreaterThanOrEqual(10, count($starters));
        $fonts = array_keys(config('site_fonts'));

        foreach ($starters as $s) {
            $this->assertContains($s['theme']['heading_font'], $fonts, $s['key']);
            $this->assertContains($s['theme']['body_font'], $fonts, $s['key']);
            $this->assertSame('Home', $s['pages'][0], $s['key']);
            $this->assertSame('hero', $s['home'][0], $s['key']);
        }

        // Each starter looks different from the others.
        $this->assertCount(count($starters), array_unique(array_map(fn ($s) => $s['theme']['palette'], $starters)));
    }

    #[Test]
    public function applying_a_starter_replaces_the_draft_not_the_live_site(): void
    {
        $this->getJson('/api/v1/site-pages')->assertOk();
        $this->postJson('/api/v1/site-builder/publish')->assertCreated();

        $this->postJson('/api/v1/site-builder/starters/cloud_kitchen')
            ->assertOk()
            ->assertJsonPath('pages.*.kind', ['home', 'menu', 'order', 'contact'])
            ->assertJsonPath('theme.palette', 'teal-fresh')
            ->assertJsonPath('theme.heading_font', 'Space Grotesk')
            ->assertJsonPath('site.has_unpublished_changes', true);

        // Visitors still see the published site, gallery and all.
        $this->getJson('/api/v1/site/spice/gallery')->assertOk();
        $this->getJson('/api/v1/site/spice')->assertJsonPath('theme.palette', 'emerald');

        $this->postJson('/api/v1/site-builder/publish')->assertCreated();
        $this->getJson('/api/v1/site/spice/gallery')->assertNotFound();
        $this->getJson('/api/v1/site/spice')->assertJsonPath('theme.heading_font', 'Space Grotesk');

        $this->postJson('/api/v1/site-builder/starters/no_such_thing')->assertNotFound();
    }

    #[Test]
    public function without_crm_a_starter_has_no_booking_page_or_button(): void
    {
        $this->tenant->update(['package_id' => Package::create([
            'name' => 'No CRM', 'modules' => ['pos', 'orders', 'catalog', 'website'], 'outlets' => 1,
        ])->id]);

        $built = app(TenantContext::class)->runFor($this->tenant, fn () => SiteStarters::build('fine_dining', $this->tenant->fresh(), withPhoto: false));

        $this->assertNotContains('reservations', array_column($built['pages'], 'kind'));
        $buttons = collect($built['pages'][0]['sections'])->where('type', 'button')->pluck('props.page')->all();
        $this->assertNotContains('reservations', $buttons);

        // And every page is one the builder accepts.
        foreach ($built['pages'] as $page) {
            $this->assertSame($page['sections'], SiteSections::clean($page['sections'], ['pos', 'orders', 'catalog', 'website']));
        }
    }

    /** The tenant on a package with every module, so hotel starters have rooms to show. */
    private function withEverything(): Tenant
    {
        $this->tenant->update(['package_id' => Package::create(['name' => 'All', 'modules' => Modules::ALL, 'outlets' => 3])->id]);

        return $this->tenant->fresh();
    }

    /** @return array{theme: array<string, mixed>, pages: list<array<string, mixed>>} */
    private function built(string $key, ?Tenant $tenant = null): array
    {
        $tenant ??= $this->tenant->fresh();

        return app(TenantContext::class)->runFor($tenant, fn () => SiteStarters::build($key, $tenant, withPhoto: false));
    }

    #[Test]
    public function hotels_resorts_and_halls_are_among_the_starters_by_category(): void
    {
        $res = $this->getJson('/api/v1/site-builder/starters')->assertOk();

        $this->assertSame(SiteStarters::CATEGORIES, $res->json('categories'));
        $byCategory = collect($res->json('starters'))->groupBy('category');
        $this->assertGreaterThanOrEqual(5, $byCategory['stay']->count());
        $this->assertNotEmpty($byCategory['events']);
        $this->assertGreaterThanOrEqual(15, $byCategory['restaurant']->count());

        // Spice Garden is on Growth, which has no hotel: the picker is told,
        // and a hall starter needs only CRM, which Growth has.
        $starter = fn (string $key) => collect($res->json('starters'))->firstWhere('key', $key);
        $this->assertSame(['hotel'], $starter('resort')['missing']);
        $this->assertSame([], $starter('convention_hall')['missing']);
        $this->assertSame([], $starter('classic')['missing']);
    }

    #[Test]
    public function a_hotel_starter_leads_with_its_rooms(): void
    {
        $tenant = $this->withEverything();
        app(TenantContext::class)->runFor($tenant, fn () => RoomType::create([
            'location_id' => Location::query()->firstOrFail()->id, 'name' => 'Deluxe', 'max_adults' => 2, 'rate' => 5000, 'is_active' => true,
        ]));

        $built = $this->built('hotel', $tenant);
        $home = $built['pages'][0]['sections'];

        $this->assertSame('rooms', $built['pages'][1]['kind']);
        $this->assertSame(['hero', 'rooms'], array_column(array_slice($home, 0, 2), 'type'));
        $this->assertSame('rooms', $home[0]['props']['button']);
        $this->assertSame('Book a room', $home[0]['props']['button_label']);
        $this->assertSame('Our rooms', $home[1]['props']['heading']);

        foreach ($built['pages'] as $page) {
            $this->assertSame($page['sections'], SiteSections::clean($page['sections'], Modules::ALL), $page['kind']);
        }
    }

    #[Test]
    public function without_the_hotel_add_on_a_hotel_starter_has_no_rooms_and_its_banner_shows_the_menu(): void
    {
        $built = $this->built('resort');
        $home = $built['pages'][0]['sections'];

        $this->assertNotContains('rooms', array_column($built['pages'], 'kind'));
        $this->assertNotContains('rooms', array_column($home, 'type'));
        $this->assertSame('menu', $home[0]['props']['button']);
    }

    #[Test]
    public function a_convention_hall_starter_leads_with_its_venues(): void
    {
        app(TenantContext::class)->runFor($this->tenant, fn () => Hall::create([
            'location_id' => Location::query()->firstOrFail()->id, 'name' => 'Grand Ballroom', 'capacity' => 500, 'price' => 150000, 'rent_type' => 'event', 'is_active' => true,
        ]));

        $built = $this->built('convention_hall');
        $home = $built['pages'][0]['sections'];

        $this->assertContains('events', array_column($built['pages'], 'kind'));
        $this->assertSame(['hero', 'venues'], array_column(array_slice($home, 0, 2), 'type'));
        $this->assertSame('events', $home[0]['props']['button']);
    }
}
