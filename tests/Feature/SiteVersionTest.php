<?php

namespace Tests\Feature;

use App\Models\SiteVersion;
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
 * The website is published as a whole, in numbered versions: every edit -
 * pages, adding and deleting them, theme, header and footer - is a draft
 * until Publish website, and an earlier version can be put back.
 */
class SiteVersionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = $this->restaurant('spice', 'Spice Garden');
        $this->actAsAdminOf($this->tenant);
    }

    private function restaurant(string $slug, string $name): Tenant
    {
        return app(TenantProvisioner::class)->create(['name' => $name, 'slug' => $slug, 'plan' => 'growth', 'status' => 'active']);
    }

    private function actAsAdminOf(Tenant $tenant): void
    {
        Sanctum::actingAs(app(TenantContext::class)->runFor($tenant, function () use ($tenant) {
            $user = User::factory()->create(['tenant_id' => $tenant->getKey(), 'name' => 'Owner']);
            $user->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

            return $user;
        }));
        $this->withHeader('X-Tenant-ID', $tenant->slug);
    }

    /** @return array<string, int> page ids by kind */
    private function pageIds(): array
    {
        return collect($this->getJson('/api/v1/site-pages')->assertOk()->json('pages'))->pluck('id', 'kind')->all();
    }

    private function setHeading(int $pageId, string $heading): void
    {
        $this->putJson("/api/v1/site-pages/{$pageId}", ['sections' => [
            ['type' => 'text', 'props' => ['heading' => $heading]],
        ]])->assertOk();
    }

    #[Test]
    public function publishing_makes_numbered_versions_of_the_whole_site(): void
    {
        $home = $this->pageIds()['home'];

        $this->setHeading($home, 'First');
        $this->postJson('/api/v1/site-builder/publish', ['note' => 'Launch'])
            ->assertCreated()
            ->assertJsonPath('version.number', 1)
            ->assertJsonPath('site.live_version', 1)
            ->assertJsonPath('site.has_unpublished_changes', false)
            ->assertJsonPath('pages.0.has_unpublished_changes', false);

        // Editing again: live stays as it was, and the editor knows it differs.
        $this->setHeading($home, 'Second');
        $this->getJson('/api/v1/site/spice')->assertJsonPath('page.sections.0.props.heading', 'First');
        $this->getJson('/api/v1/site-pages')
            ->assertJsonPath('site.has_unpublished_changes', true)
            ->assertJsonPath('pages.0.has_unpublished_changes', true)
            ->assertJsonPath('pages.1.has_unpublished_changes', false);

        $this->postJson('/api/v1/site-builder/publish')->assertCreated()->assertJsonPath('version.number', 2);
        $this->getJson('/api/v1/site/spice')
            ->assertJsonPath('page.sections.0.props.heading', 'Second')
            ->assertJsonPath('version', 2);

        $this->getJson('/api/v1/site-builder/versions')
            ->assertOk()
            ->assertJsonPath('versions.0.number', 2)
            ->assertJsonPath('versions.0.is_live', true)
            ->assertJsonPath('versions.0.published_by', 'Owner')
            ->assertJsonPath('versions.1.note', 'Launch')
            ->assertJsonPath('versions.1.is_live', false);
    }

    #[Test]
    public function adding_and_deleting_pages_waits_for_publish_too(): void
    {
        $ids = $this->pageIds();
        $this->postJson('/api/v1/site-builder/publish')->assertCreated();

        $this->deleteJson("/api/v1/site-pages/{$ids['gallery']}")->assertOk();
        $this->postJson('/api/v1/site-pages', ['title' => 'Catering'])->assertCreated();

        $this->getJson('/api/v1/site/spice/gallery')->assertOk();
        $this->getJson('/api/v1/site/spice/catering')->assertNotFound();

        $this->postJson('/api/v1/site-builder/publish')->assertCreated();

        $this->getJson('/api/v1/site/spice/gallery')->assertNotFound();
        $this->getJson('/api/v1/site/spice/catering')->assertOk();
    }

    #[Test]
    public function the_site_can_be_put_back_to_an_earlier_version(): void
    {
        $home = $this->pageIds()['home'];
        $this->setHeading($home, 'Old');
        $this->postJson('/api/v1/site-builder/publish')->assertCreated();
        $this->setHeading($home, 'New');
        $this->postJson('/api/v1/site-builder/publish')->assertCreated();

        $first = SiteVersion::withoutGlobalScopes()->where('number', 1)->value('id');

        $this->postJson("/api/v1/site-builder/versions/{$first}/make-live")
            ->assertOk()
            ->assertJsonPath('site.live_version', 1)
            ->assertJsonPath('site.latest_version', 2);
        $this->getJson('/api/v1/site/spice')->assertJsonPath('page.sections.0.props.heading', 'Old');

        // The editor's draft is untouched by a rollback.
        $this->getJson('/api/v1/site-pages')->assertJsonPath('pages.0.sections.0.props.heading', 'New');
    }

    #[Test]
    public function a_version_can_be_loaded_into_the_editor(): void
    {
        $ids = $this->pageIds();
        $this->putJson('/api/v1/site-builder/theme', ['palette' => 'ocean', 'font' => 'classic'])->assertOk();
        $this->setHeading($ids['home'], 'Kept');
        $this->postJson('/api/v1/site-builder/publish')->assertCreated();

        $this->putJson('/api/v1/site-builder/theme', ['palette' => 'crimson', 'font' => 'modern'])->assertOk();
        $this->setHeading($ids['home'], 'Thrown away');
        $this->deleteJson("/api/v1/site-pages/{$ids['about']}")->assertOk();

        $version = SiteVersion::withoutGlobalScopes()->value('id');
        $this->postJson("/api/v1/site-builder/versions/{$version}/edit")
            ->assertOk()
            ->assertJsonPath('theme.palette', 'ocean')
            ->assertJsonPath('pages.0.sections.0.props.heading', 'Kept')
            ->assertJsonPath('pages.4.kind', 'about')
            // Same ids as the live pages, so nothing shows as changed.
            ->assertJsonPath('pages.4.id', $ids['about'])
            ->assertJsonPath('site.has_unpublished_changes', false);
    }

    #[Test]
    public function one_restaurant_cannot_touch_another_restaurants_versions(): void
    {
        $this->pageIds();
        $this->postJson('/api/v1/site-builder/publish')->assertCreated();
        $theirs = SiteVersion::withoutGlobalScopes()->value('id');

        $this->actAsAdminOf($this->restaurant('rival', 'Rival Kitchen'));

        $this->postJson("/api/v1/site-builder/versions/{$theirs}/make-live")->assertNotFound();
        $this->postJson("/api/v1/site-builder/versions/{$theirs}/edit")->assertNotFound();
        $this->getJson('/api/v1/site-builder/versions')->assertOk()->assertJsonCount(0, 'versions');
    }

    #[Test]
    public function standard_pages_can_be_added_and_ordered(): void
    {
        $ids = $this->pageIds();
        $this->deleteJson("/api/v1/site-pages/{$ids['contact']}")->assertOk();
        $this->deleteJson("/api/v1/site-pages/{$ids['menu']}")->assertOk();
        // The owner's own page took the address a standard page would use.
        $this->postJson('/api/v1/site-pages', ['title' => 'Menu'])->assertCreated();

        $this->getJson('/api/v1/site-pages')
            ->assertJsonPath('standard_pages.0.kind', 'menu')
            ->assertJsonPath('standard_pages.0.added', false);

        $this->postJson('/api/v1/site-pages', ['kind' => 'contact'])
            ->assertCreated()
            ->assertJsonPath('page.slug', 'contact')
            ->assertJsonPath('page.sections.0.type', 'contact');
        $this->postJson('/api/v1/site-pages', ['kind' => 'contact'])->assertUnprocessable();
        $this->postJson('/api/v1/site-pages', ['kind' => 'home'])->assertUnprocessable();

        $added = $this->postJson('/api/v1/site-builder/standard-pages')->assertOk();
        $this->assertSame(['Menu'], $added->json('added'));
        $this->assertContains('menu-2', $added->json('pages.*.slug'));

        // Navigation order.
        $pages = collect($added->json('pages'));
        $reversed = $pages->pluck('id')->reverse()->values()->all();
        $this->putJson('/api/v1/site-builder/page-order', ['ids' => $reversed])
            ->assertOk()
            ->assertJsonPath('pages.0.id', $reversed[0]);
    }

    #[Test]
    public function the_order_button_goes_to_the_sites_own_ordering_page(): void
    {
        $this->pageIds();
        $this->postJson('/api/v1/site-builder/publish')->assertCreated();

        $this->getJson('/api/v1/site/spice')
            ->assertJsonPath('order_page', 'order')
            ->assertJsonPath('pages.2.kind', 'order');
        $this->getJson('/api/v1/site/spice/order')->assertJsonPath('page.sections.0.type', 'online_order');
    }
}
