<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
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
 * The restaurant decides the order its categories appear in - the POS tabs,
 * the online order page and the storefront all read the same list.
 */
class CategoryOrderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Ordered', 'slug' => 'ordered', 'plan' => 'growth', 'status' => 'active',
        ]);

        // Provisioning seeds a few categories of its own; start from none.
        $this->inTenant(fn () => ProductCategory::query()->delete());
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function actingAsRole(string $role): static
    {
        $user = $this->inTenant(function () use ($role) {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $user->assignRole($role);

            return $user;
        });

        Sanctum::actingAs($user);

        return $this->withHeader('X-Tenant-ID', 'ordered');
    }

    /** @return list<string> category names as the API lists them */
    private function listed(): array
    {
        return collect($this->withHeader('X-Tenant-ID', 'ordered')->getJson('/api/v1/product-categories?nopaginate=1')->json())
            ->pluck('name')->all();
    }

    #[Test]
    public function new_categories_go_to_the_end_and_the_list_follows_the_order(): void
    {
        $owner = $this->actingAsRole(RoleDefinitions::RESTAURANT_ADMIN);

        foreach (['Drinks', 'Mains', 'Desserts'] as $name) {
            $owner->postJson('/api/v1/product-categories', ['name' => $name])->assertCreated();
        }

        $this->assertSame(['Drinks', 'Mains', 'Desserts'], $this->listed());
    }

    #[Test]
    public function the_owner_can_reorder_them(): void
    {
        $owner = $this->actingAsRole(RoleDefinitions::RESTAURANT_ADMIN);
        $ids = collect(['Drinks', 'Mains', 'Desserts'])
            ->mapWithKeys(fn ($name) => [$name => $owner->postJson('/api/v1/product-categories', ['name' => $name])->json('id')]);

        $owner->putJson('/api/v1/product-categories/reorder', ['ids' => [$ids['Mains'], $ids['Desserts'], $ids['Drinks']]])
            ->assertOk()
            ->assertJsonPath('0.name', 'Mains');

        $this->assertSame(['Mains', 'Desserts', 'Drinks'], $this->listed());
    }

    #[Test]
    public function the_online_order_page_uses_the_same_order(): void
    {
        $owner = $this->actingAsRole(RoleDefinitions::RESTAURANT_ADMIN);
        $ids = collect(['Drinks', 'Mains'])
            ->mapWithKeys(fn ($name) => [$name => $owner->postJson('/api/v1/product-categories', ['name' => $name])->json('id')]);

        $this->inTenant(fn () => $ids->each(fn ($id, $name) => Product::create([
            'name' => $name.' item', 'price' => 100, 'category_id' => $id, 'is_active' => true,
        ])));

        $owner->putJson('/api/v1/product-categories/reorder', ['ids' => [$ids['Mains'], $ids['Drinks']]])->assertOk();

        $this->getJson('/api/v1/order/ordered')
            ->assertOk()
            ->assertJsonPath('categories.0.name', 'Mains')
            ->assertJsonPath('categories.1.name', 'Drinks');
    }

    #[Test]
    public function a_stale_list_is_refused_rather_than_half_applied(): void
    {
        $owner = $this->actingAsRole(RoleDefinitions::RESTAURANT_ADMIN);
        $first = $owner->postJson('/api/v1/product-categories', ['name' => 'Drinks'])->json('id');
        $owner->postJson('/api/v1/product-categories', ['name' => 'Mains']);

        // Missing one, and naming one that is not this restaurant's.
        $owner->putJson('/api/v1/product-categories/reorder', ['ids' => [$first]])->assertUnprocessable();
        $owner->putJson('/api/v1/product-categories/reorder', ['ids' => [$first, 999999]])->assertUnprocessable();

        $this->assertSame(['Drinks', 'Mains'], $this->listed());
    }

    #[Test]
    public function staff_without_catalog_rights_cannot_reorder(): void
    {
        $this->actingAsRole(RoleDefinitions::RESTAURANT_ADMIN)->postJson('/api/v1/product-categories', ['name' => 'Drinks']);
        $id = $this->inTenant(fn () => ProductCategory::value('id'));

        $this->actingAsRole('chef')
            ->putJson('/api/v1/product-categories/reorder', ['ids' => [$id]])
            ->assertForbidden();
    }
}
