<?php

namespace Tests\Feature;

use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The tests that matter for multi-tenancy: not "does the endpoint work" but
 * "can one restaurant reach another's data".
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create(['slug' => 'tenant-a']);
        $this->tenantB = Tenant::factory()->create(['slug' => 'tenant-b']);
    }

    private function categoryFor(Tenant $tenant, string $name): ProductCategory
    {
        return app(TenantContext::class)->runFor(
            $tenant,
            fn () => ProductCategory::create(['name' => $name, 'slug' => strtolower($name)]),
        );
    }

    public function test_index_returns_only_the_callers_tenant_rows(): void
    {
        $this->categoryFor($this->tenantA, 'Alpha');
        $this->categoryFor($this->tenantB, 'Beta');

        Sanctum::actingAs(User::factory()->forTenant($this->tenantA)->create());

        $response = $this->getJson('/api/v1/product-categories?nopaginate=1');

        $response->assertOk();

        $names = collect($response->json('data') ?? $response->json())->pluck('name');

        $this->assertContains('Alpha', $names);
        $this->assertNotContains('Beta', $names, 'Tenant A can see tenant B\'s categories.');
    }

    public function test_showing_another_tenants_record_by_id_is_not_found(): void
    {
        $foreign = $this->categoryFor($this->tenantB, 'Beta');

        Sanctum::actingAs(User::factory()->forTenant($this->tenantA)->create());

        // 404 rather than 403: tenant A should not be able to tell whether that
        // id exists at all.
        $this->getJson("/api/v1/product-categories/{$foreign->id}")->assertNotFound();
    }

    public function test_forged_tenant_header_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->forTenant($this->tenantA)->create());

        $this->getJson('/api/v1/product-categories', [
            'X-Tenant-ID' => $this->tenantB->slug,
        ])->assertForbidden();
    }

    public function test_platform_admin_may_target_another_tenant(): void
    {
        $this->categoryFor($this->tenantB, 'Beta');

        Sanctum::actingAs(
            User::factory()->forTenant($this->tenantA)->platformAdmin()->create()
        );

        $response = $this->getJson('/api/v1/product-categories?nopaginate=1', [
            'X-Tenant-ID' => $this->tenantB->slug,
        ]);

        $response->assertOk();

        $names = collect($response->json('data') ?? $response->json())->pluck('name');
        $this->assertContains('Beta', $names);
    }

    public function test_writes_are_stamped_with_the_callers_tenant(): void
    {
        Sanctum::actingAs(User::factory()->forTenant($this->tenantA)->create());

        // tenant_id is not fillable, so even an explicit attempt to plant the
        // row in another tenant must be ignored.
        $this->postJson('/api/v1/product-categories', [
            'name' => 'Planted',
            'slug' => 'planted',
            'tenant_id' => $this->tenantB->id,
        ])->assertCreated();

        $this->assertDatabaseHas('product_categories', [
            'slug' => 'planted',
            'tenant_id' => $this->tenantA->id,
        ]);
    }

    public function test_same_slug_may_exist_in_two_tenants(): void
    {
        $this->categoryFor($this->tenantA, 'Desserts');

        // The whole point of the per-tenant unique constraints: the second
        // restaurant to sign up must still be able to create "Desserts".
        $this->categoryFor($this->tenantB, 'Desserts');

        $this->assertSame(2, ProductCategory::withoutGlobalScopes()->where('slug', 'desserts')->count());
    }

    public function test_suspended_tenant_is_refused(): void
    {
        $suspended = Tenant::factory()->suspended()->create();

        Sanctum::actingAs(User::factory()->forTenant($suspended)->create());

        $this->getJson('/api/v1/product-categories')->assertForbidden();
    }

    public function test_unauthenticated_request_without_a_tenant_header_is_rejected(): void
    {
        // The public storefront has no token, so the header is the only thing
        // identifying the restaurant. Missing it must fail rather than fall
        // back to some default tenant.
        $this->getJson('/api/v1/product-categories')->assertStatus(400);
    }
}
