<?php

namespace Tests\Feature;

use App\Models\GoogleReview;
use App\Models\Location;
use App\Models\Package;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The smart first draft: a restaurant's site put together from its own data,
 * by rules. Each section appears only when there is something to show.
 */
class SiteDraftTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Spice Garden', 'slug' => 'spice', 'plan' => 'growth', 'status' => 'active',
        ]);

        Sanctum::actingAs($this->inTenant(function () {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $user->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

            return $user;
        }));
        $this->withHeader('X-Tenant-ID', 'spice');

        // Start from an empty menu: provisioning seeds a few categories.
        $this->inTenant(fn () => ProductCategory::query()->delete());
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    /** Adds dishes to a category, with a stored photo each when asked. */
    private function dishes(string $category, int $count, bool $photos = false): void
    {
        $this->inTenant(function () use ($category, $count, $photos) {
            $cat = ProductCategory::create(['name' => $category, 'is_active' => true]);

            for ($i = 1; $i <= $count; $i++) {
                $product = Product::create(['name' => "{$category} {$i}", 'price' => 100, 'category_id' => $cat->id, 'is_active' => true]);
                if ($photos) {
                    $path = "foods/{$category}-{$i}.jpg";
                    Storage::disk('public')->put($path, 'jpeg-bytes');
                    $product->images()->create(['url' => $path]);
                }
            }
        });
    }

    /** @return list<string> */
    private function types(): array
    {
        return array_column($this->getJson('/api/v1/site-builder/smart-draft')->assertOk()->json('sections'), 'type');
    }

    #[Test]
    public function a_restaurant_with_little_data_gets_the_essentials(): void
    {
        $this->inTenant(fn () => $this->tenant->update(['package_id' => Package::create([
            'name' => 'Basic site', 'modules' => ['pos', 'orders', 'catalog', 'website'], 'outlets' => 1,
        ])->id]));

        $this->assertSame(['hero', 'text', 'menu', 'order_button', 'contact'], $this->types());
    }

    #[Test]
    public function sections_appear_when_there_is_something_to_show(): void
    {
        $this->dishes('Biryani', 4, photos: true);
        $this->dishes('Kebab', 2);
        $this->inTenant(function () {
            GoogleReview::create(['author_name' => 'Rina', 'rating' => 5, 'text' => 'Lovely', 'is_displayed' => true]);
            Location::create(['name' => 'Gulshan', 'slug' => 'gulshan', 'is_active' => true]);
        });

        $draft = $this->getJson('/api/v1/site-builder/smart-draft')->assertOk();
        $types = array_column($draft->json('sections'), 'type');

        // Growth includes CRM (reservations); two branches now.
        $this->assertSame(['hero', 'text', 'featured', 'menu', 'order_button', 'reviews', 'reservations', 'branches', 'contact'], $types);

        // The words come from the restaurant's own menu and name.
        $this->assertSame('Spice Garden', $draft->json('sections.0.props.title'));
        $this->assertStringContainsString('Biryani and Kebab', $draft->json('sections.0.props.subtitle'));
        $this->assertStringContainsString('Welcome to Spice Garden', $draft->json('sections.1.props.body'));
        $this->assertStringContainsString('Spice Garden', $draft->json('meta_description'));
    }

    #[Test]
    public function a_dish_photo_becomes_the_banner_as_a_copy(): void
    {
        $this->dishes('Biryani', 3, photos: true);

        $image = $this->getJson('/api/v1/site-builder/smart-draft')->json('sections.0.props.image');

        $this->assertStringStartsWith('site/'.$this->tenant->getKey().'/banner-', $image);
        Storage::disk('public')->assertExists($image);
    }

    #[Test]
    public function the_first_builder_visit_and_the_public_site_use_the_draft(): void
    {
        $this->dishes('Biryani', 3, photos: true);

        // Public, never published: drafted, but no files written on a visitor's request.
        $public = $this->getJson('/api/v1/site/spice')->assertOk();
        $this->assertSame('text', $public->json('page.sections.1.type'));
        $this->assertSame('', $public->json('page.sections.0.props.image'));
        $this->assertNotNull($public->json('page.meta_description'));
        $this->assertSame([], Storage::disk('public')->files('site/'.$this->tenant->getKey()));

        // First visit to the builder saves the draft as the home page.
        $this->getJson('/api/v1/site-pages')
            ->assertJsonPath('pages.0.sections.2.type', 'featured')
            ->assertJsonPath('pages.0.is_published', false);
    }
}
