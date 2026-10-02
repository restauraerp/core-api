<?php

namespace Tests\Feature;

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
    public function every_restaurant_with_the_website_module_has_a_starter_site(): void
    {
        $this->getJson('/api/v1/site/spice')
            ->assertOk()
            ->assertJsonPath('restaurant.code', 'spice')
            ->assertJsonPath('page.sections.0.type', 'hero')
            ->assertJsonPath('page.sections.1.type', 'menu');
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
            ->assertJsonPath('pages.0.is_published', false)
            ->assertJsonPath('pages.0.sections.0.type', 'hero');

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

        $this->getJson('/api/v1/site/spice')->assertJsonPath('page.sections.0.type', 'hero');

        $this->postJson("/api/v1/site-pages/{$id}/publish")->assertOk()->assertJsonPath('page.is_published', true);

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
        $this->postJson("/api/v1/site-pages/{$page['id']}/publish")->assertOk();
        $this->getJson('/api/v1/site/spice/about-us')->assertOk()->assertJsonPath('page.title', 'About Us');
    }

    #[Test]
    public function the_home_page_stays(): void
    {
        $id = $this->homeId();

        $this->deleteJson("/api/v1/site-pages/{$id}")->assertUnprocessable();
        $this->postJson("/api/v1/site-pages/{$id}/unpublish")->assertUnprocessable();
        $this->putJson("/api/v1/site-pages/{$id}", ['slug' => 'elsewhere'])->assertUnprocessable();
    }

    #[Test]
    public function only_people_who_manage_the_website_can_edit_it(): void
    {
        $this->asRole('chef')->getJson('/api/v1/site-pages')->assertForbidden();
    }
}
