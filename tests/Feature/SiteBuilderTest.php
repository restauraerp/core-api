<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Package;
use App\Models\Reservation;
use App\Models\SiteVersion;
use App\Models\SocialLink;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The in-house site builder: pages as lists of known components, checked on
 * every save so no custom code reaches a restaurant's site, and nothing
 * public until it is published.
 */
class SiteBuilderTest extends TestCase
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
    }

    private function asRole(string $role): static
    {
        $user = app(TenantContext::class)->runFor($this->tenant, function () use ($role) {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $user->assignRole($role);

            return $user;
        });

        Sanctum::actingAs($user);

        return $this->withHeader('X-Tenant-ID', 'spice');
    }

    private function homeId(): int
    {
        return $this->asRole(RoleDefinitions::RESTAURANT_ADMIN)->getJson('/api/v1/site-pages')->json('pages.0.id');
    }

    #[Test]
    public function a_site_never_published_is_coming_soon(): void
    {
        $this->getJson('/api/v1/site/spice')
            ->assertOk()
            ->assertJsonPath('coming_soon', true)
            ->assertJsonPath('restaurant.code', 'spice')
            ->assertJsonPath('page.sections', [])
            ->assertJsonPath('pages', [])
            ->assertJsonPath('tracking', null);

        // Only the home address answers until then.
        $this->getJson('/api/v1/site/spice/menu')->assertNotFound();

        // Drafts in the editor change nothing for visitors.
        $this->asRole(RoleDefinitions::RESTAURANT_ADMIN)->getJson('/api/v1/site-pages')->assertOk();
        $this->getJson('/api/v1/site/spice')->assertJsonPath('coming_soon', true);

        $this->postJson('/api/v1/site-builder/publish')->assertCreated();
        $this->getJson('/api/v1/site/spice')
            ->assertJsonPath('coming_soon', false)
            ->assertJsonPath('page.sections.0.type', 'hero');
        $this->getJson('/api/v1/site/spice/menu')->assertOk();
    }

    #[Test]
    public function no_website_module_means_no_site(): void
    {
        $this->tenant->update(['plan' => 'starter']);

        $this->getJson('/api/v1/site/spice')->assertNotFound();
        $this->getJson('/api/v1/site/no-such-restaurant')->assertNotFound();
    }

    #[Test]
    public function the_builder_starts_from_the_starter_home(): void
    {
        $this->asRole(RoleDefinitions::RESTAURANT_ADMIN)->getJson('/api/v1/site-pages')
            ->assertOk()
            ->assertJsonPath('site_code', 'spice')
            ->assertJsonPath('pages.0.is_home', true)
            ->assertJsonPath('pages.0.is_live', false)
            ->assertJsonPath('pages.0.sections.0.type', 'hero')
            // A full site of standard pages, not just a home page.
            ->assertJsonPath('pages.*.kind', ['home', 'menu', 'order', 'reservations', 'about', 'gallery', 'locations', 'contact'])
            ->assertJsonPath('site.live_version', null)
            ->assertJsonPath('site.has_unpublished_changes', true);

        $this->getJson('/api/v1/site-builder/components')
            ->assertOk()
            ->assertJsonPath('components.menu.kind', 'live')
            ->assertJsonPath('components.text.kind', 'content');
    }

    #[Test]
    public function a_draft_is_not_public_until_it_is_published(): void
    {
        $id = $this->homeId();

        $this->putJson("/api/v1/site-pages/{$id}", ['sections' => [
            ['type' => 'text', 'props' => ['heading' => 'Our story', 'body' => 'Since 1998.']],
        ]])->assertOk();

        $this->getJson('/api/v1/site/spice')->assertJsonPath('coming_soon', true);

        $this->postJson('/api/v1/site-builder/publish')->assertCreated()->assertJsonPath('version.number', 1);

        $this->getJson('/api/v1/site/spice')
            ->assertJsonPath('page.sections.0.type', 'text')
            ->assertJsonPath('page.sections.0.props.heading', 'Our story')
            // Missing settings take their defaults.
            ->assertJsonPath('page.sections.0.props.align', 'left');
    }

    #[Test]
    public function no_custom_code_reaches_the_site(): void
    {
        $id = $this->homeId();
        $save = fn (array $section) => $this->putJson("/api/v1/site-pages/{$id}", ['sections' => [$section]]);

        $save(['type' => 'text', 'props' => ['body' => '<script>alert(1)</script>']])
            ->assertUnprocessable()->assertJsonValidationErrors('sections');
        $save(['type' => 'html_embed', 'props' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('sections');
        $save(['type' => 'text', 'props' => ['align' => 'justify']])
            ->assertUnprocessable();
        $save(['type' => 'image', 'props' => ['image' => 'https://evil.example/x.png']])
            ->assertUnprocessable();
        $save(['type' => 'image', 'props' => ['image' => 'site/../../.env']])
            ->assertUnprocessable();

        // Settings a component does not have are dropped, not stored.
        $save(['type' => 'spacer', 'props' => ['size' => 'large', 'onclick' => 'steal()']])
            ->assertOk()
            ->assertJsonMissingPath('page.sections.0.props.onclick')
            ->assertJsonPath('page.sections.0.props.size', 'large');
    }

    #[Test]
    public function extra_pages_get_their_own_address(): void
    {
        $this->homeId();

        $page = $this->postJson('/api/v1/site-pages', ['title' => 'About Us'])
            ->assertCreated()
            ->assertJsonPath('page.slug', 'about-us')
            ->json('page');

        $this->postJson('/api/v1/site-pages', ['title' => 'About Us'])->assertUnprocessable();

        $this->getJson('/api/v1/site/spice/about-us')->assertNotFound();
        $this->postJson('/api/v1/site-builder/publish')->assertCreated();
        $this->getJson('/api/v1/site/spice/about-us')->assertOk()->assertJsonPath('page.title', 'About Us');
    }

    #[Test]
    public function the_home_page_stays(): void
    {
        $id = $this->homeId();

        $this->deleteJson("/api/v1/site-pages/{$id}")->assertUnprocessable();
        $this->putJson("/api/v1/site-pages/{$id}", ['slug' => 'elsewhere'])->assertUnprocessable();
    }

    #[Test]
    public function only_people_who_manage_the_website_can_edit_it(): void
    {
        $this->asRole('chef')->getJson('/api/v1/site-pages')->assertForbidden();
    }

    #[Test]
    public function the_theme_is_chosen_from_fixed_palettes_and_fonts(): void
    {
        $this->asRole(RoleDefinitions::RESTAURANT_ADMIN);

        $this->getJson('/api/v1/site/spice')->assertJsonPath('theme.palette', 'emerald')->assertJsonPath('theme.heading_font', 'Inter');

        $this->putJson('/api/v1/site-builder/theme', ['palette' => 'saffron', 'font' => 'bangla'])->assertOk();
        $this->putJson('/api/v1/site-builder/theme', ['palette' => '#ff0000', 'font' => 'comic-sans'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['palette', 'font']);

        // A draft like the pages: live on the next Publish website.
        $this->getJson('/api/v1/site/spice')->assertJsonPath('theme.palette', 'emerald');
        $this->getJson('/api/v1/site-pages')->assertJsonPath('site.has_unpublished_changes', true);
        $this->postJson('/api/v1/site-builder/publish')->assertCreated();
        $this->getJson('/api/v1/site/spice')->assertJsonPath('theme.palette', 'saffron')->assertJsonPath('theme.body_font', 'Hind Siliguri');
    }

    #[Test]
    public function a_page_carries_what_search_engines_show(): void
    {
        $id = $this->homeId();

        $this->putJson("/api/v1/site-pages/{$id}", [
            'meta_title' => 'Spice Garden - Biryani in Dhanmondi',
            'meta_description' => 'Order online or book a table.',
            'og_image' => 'site/1/share.jpg',
        ])->assertOk()->assertJsonPath('page.meta_title', 'Spice Garden - Biryani in Dhanmondi');

        $this->putJson("/api/v1/site-pages/{$id}", ['og_image' => 'https://evil.example/x.jpg'])->assertUnprocessable();
        $this->putJson("/api/v1/site-pages/{$id}", ['meta_title' => str_repeat('x', 71)])->assertUnprocessable();

        $this->postJson('/api/v1/site-builder/publish')->assertCreated();
        $this->getJson('/api/v1/site/spice')
            ->assertJsonPath('page.meta_description', 'Order online or book a table.')
            ->assertJsonPath('page.og_image', 'site/1/share.jpg');
    }

    #[Test]
    public function a_gallery_takes_our_own_photos_only(): void
    {
        $id = $this->homeId();
        $gallery = fn (array $images) => $this->putJson("/api/v1/site-pages/{$id}", ['sections' => [['type' => 'gallery', 'props' => ['images' => $images]]]]);

        $gallery(['site/1/a.jpg', 'site/1/b.jpg'])->assertOk()->assertJsonPath('page.sections.0.props.images.1', 'site/1/b.jpg');
        $gallery(['site/1/a.jpg', 'javascript:alert(1)'])->assertUnprocessable();
        $gallery(array_fill(0, 13, 'site/1/a.jpg'))->assertUnprocessable();
    }

    #[Test]
    public function reservations_need_the_crm_module(): void
    {
        $package = Package::create(['name' => 'Site only', 'modules' => ['pos', 'orders', 'website'], 'outlets' => 1]);
        $this->tenant->update(['package_id' => $package->id]);

        $this->asRole(RoleDefinitions::RESTAURANT_ADMIN)->getJson('/api/v1/site-builder/components')
            ->assertOk()
            ->assertJsonMissingPath('components.reservations')
            ->assertJsonPath('components.menu.kind', 'live');

        $id = $this->getJson('/api/v1/site-pages')->json('pages.0.id');
        $this->putJson("/api/v1/site-pages/{$id}", ['sections' => [['type' => 'reservations']]])->assertUnprocessable();

        $this->postJson('/api/v1/site/spice/reservations', [])->assertNotFound();
    }

    #[Test]
    public function a_guest_can_request_a_table(): void
    {
        $location = app(TenantContext::class)->runFor($this->tenant, fn () => Location::query()->firstOrFail());
        $tomorrow = now()->addDay()->format('Y-m-d');
        $request = fn (array $overrides = []) => $this->postJson('/api/v1/site/spice/reservations', array_merge([
            'name' => 'Rahim', 'email' => 'rahim@example.com', 'phone' => '01711223344', 'location_id' => $location->id,
            'date' => $tomorrow, 'time' => '19:30', 'guests' => 4, 'notes' => 'Window seat',
        ], $overrides));

        $request()->assertCreated()->assertJsonPath('guests', 4);

        [$reservation, $customer] = app(TenantContext::class)->runFor($this->tenant, function () {
            $reservation = Reservation::sole();

            return [$reservation, Customer::find($reservation->customer_id)];
        });
        $this->assertSame('Pending', $reservation->status);
        $this->assertSame('website', $reservation->source);
        $this->assertSame('Window seat', $reservation->notes);
        $this->assertSame('Rahim', $customer->name);

        $request(['date' => now()->subDay()->format('Y-m-d')])->assertUnprocessable()->assertJsonValidationErrors('date');
        $request(['location_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $request(['guests' => 0])->assertUnprocessable();
    }

    #[Test]
    public function only_web_addresses_are_offered_as_social_links(): void
    {
        app(TenantContext::class)->runFor($this->tenant, function () {
            SocialLink::create(['platform' => 'Facebook', 'url' => 'https://facebook.com/spice', 'is_active' => true]);
            SocialLink::create(['platform' => 'Evil', 'url' => 'javascript:alert(1)', 'is_active' => true]);
        });

        $id = $this->homeId();
        $this->putJson("/api/v1/site-pages/{$id}", ['sections' => [['type' => 'social']]])->assertOk();
        $this->postJson('/api/v1/site-builder/publish')->assertCreated();

        $this->getJson('/api/v1/site/spice')
            ->assertJsonCount(1, 'social')
            ->assertJsonPath('social.0.url', 'https://facebook.com/spice');
    }

    #[Test]
    public function a_map_shows_branches_chosen_by_id(): void
    {
        $id = $this->homeId();
        $map = fn (mixed $locations) => $this->putJson("/api/v1/site-pages/{$id}", ['sections' => [['type' => 'map', 'props' => ['locations' => $locations, 'layout' => 'grid']]]]);

        $map([3, 5])->assertOk()->assertJsonPath('page.sections.0.props.locations', [3, 5])->assertJsonPath('page.sections.0.props.height', 'medium');
        $map(['javascript:alert(1)'])->assertUnprocessable();
        $this->putJson("/api/v1/site-pages/{$id}", ['sections' => [['type' => 'map', 'props' => ['height' => '9999px']]]])->assertUnprocessable();
    }

    #[Test]
    public function colours_and_fonts_are_any_valid_values_from_the_catalogue(): void
    {
        $this->asRole(RoleDefinitions::RESTAURANT_ADMIN)->getJson('/api/v1/site-pages')->assertOk();
        $colors = ['primary' => '#d97706', 'secondary' => '#7C2D12', 'accent' => '#0F766E', 'neutral' => '#1C1917', 'base' => '#FFFBF5'];

        $this->putJson('/api/v1/site-builder/theme', [
            'palette' => 'custom', 'colors' => $colors, 'heading_font' => 'Playfair Display', 'body_font' => 'Lato',
        ])->assertOk()
            ->assertJsonPath('theme.colors.primary', '#D97706')
            ->assertJsonPath('theme.heading_font', 'Playfair Display');

        // Not a colour, an unknown colour slot, a font we do not offer.
        $this->putJson('/api/v1/site-builder/theme', ['colors' => ['primary' => 'red'] + $colors])->assertUnprocessable()->assertJsonValidationErrors('colors.primary');
        $this->putJson('/api/v1/site-builder/theme', ['colors' => $colors + ['evil' => '#000000']])->assertUnprocessable()->assertJsonValidationErrors('colors');
        $this->putJson('/api/v1/site-builder/theme', ['heading_font' => 'Comic Sans MS'])->assertUnprocessable()->assertJsonValidationErrors('heading_font');
        $this->putJson('/api/v1/site-builder/theme', ['palette' => 'x" onload="'])->assertUnprocessable();

        $this->postJson('/api/v1/site-builder/publish')->assertCreated();
        $this->getJson('/api/v1/site/spice')
            ->assertJsonPath('theme.colors.base', '#FFFBF5')
            ->assertJsonPath('fonts.heading', ['name' => 'Playfair Display', 'category' => 'serif', 'weights' => '400;700'])
            ->assertJsonPath('fonts.body.name', 'Lato');
    }

    #[Test]
    public function a_version_from_before_font_pairs_still_reads_and_is_not_a_change(): void
    {
        $this->asRole(RoleDefinitions::RESTAURANT_ADMIN)->getJson('/api/v1/site-pages')->assertOk();
        $this->postJson('/api/v1/site-builder/publish')->assertCreated();

        // As the versions migration wrote them: a palette name and one style.
        SiteVersion::withoutGlobalScopes()->get()->each(function ($v) {
            $snapshot = $v->snapshot;
            $snapshot['theme'] = ['palette' => 'emerald', 'font' => 'modern', 'header_order_button' => true, 'footer_social' => true, 'footer_text' => ''];
            $v->forceFill(['snapshot' => $snapshot])->save();
        });

        $this->getJson('/api/v1/site/spice')->assertJsonPath('theme.heading_font', 'Inter')->assertJsonPath('theme.colors', null);
        $this->getJson('/api/v1/site-pages')->assertJsonPath('site.has_unpublished_changes', false);
    }
}
