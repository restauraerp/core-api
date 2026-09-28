<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\Modules;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Custom packages: modules and an outlet cap made for particular restaurants.
 *
 * The case that asked for them: a Starter restaurant has no Delivery module,
 * so nobody can be assigned a delivery, so the till must not take one - while a
 * delivery-only cloud kitchen needs Delivery without the rest of Growth.
 */
class CustomPackageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['platform.token' => 'test-secret']);
        $this->seed(RolePermissionSeeder::class);

        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Cloud Kitchen',
            'slug' => 'cloud-kitchen',
            'plan' => 'starter',
            'status' => 'active',
        ]);

        $this->owner = $this->inTenant(function () {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $user->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

            return $user;
        });
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function platform(): static
    {
        return $this->withToken('test-secret');
    }

    private function cloudKitchen(array $overrides = []): Package
    {
        return Package::create(array_merge([
            'name' => 'Cloud kitchen',
            'based_on' => 'starter',
            'modules' => [...Modules::CORE, Modules::DELIVERY],
            'outlets' => 2,
        ], $overrides));
    }

    private function assign(?Package $package): TestResponse
    {
        return $this->platform()->putJson("/api/v1/platform/tenants/{$this->tenant->slug}/package", [
            'package_id' => $package?->getKey(),
        ]);
    }

    private function placeDelivery(): TestResponse
    {
        [$location, $product] = $this->inTenant(fn () => [
            Location::query()->firstOrFail(),
            Product::create(['name' => 'Biryani', 'price' => 500, 'type' => 'food']),
        ]);

        Sanctum::actingAs($this->owner);

        return $this->withHeader('X-Tenant-ID', $this->tenant->slug)->postJson('/api/v1/orders', [
            'location_id' => $location->id,
            'order_type' => 'delivery',
            'subtotal' => 500,
            'discount_amount' => 0,
            'delivery_address' => 'Road 1, Banani',
            'items' => [['product_id' => $product->id, 'qty' => 1, 'price' => 500]],
        ]);
    }

    #[Test]
    public function a_starter_restaurant_cannot_take_a_delivery_order(): void
    {
        $this->placeDelivery()
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_type');

        $this->assertSame(0, $this->inTenant(fn () => Order::count()));
    }

    #[Test]
    public function a_starter_restaurant_offers_no_delivery_online(): void
    {
        $this->getJson("/api/v1/order/{$this->tenant->slug}")
            ->assertOk()
            ->assertJsonPath('order_types', ['takeaway']);
    }

    #[Test]
    public function a_package_is_made_from_a_copy_of_a_default(): void
    {
        $this->platform()->postJson('/api/v1/platform/packages', [
            'name' => 'Cloud kitchen',
            'based_on' => 'starter',
            // Out of order and repeated; stored canonical.
            'modules' => ['delivery', 'pos', 'orders', 'pos'],
            'outlets' => null,
        ])
            ->assertCreated()
            ->assertJsonPath('package.modules', ['pos', 'orders', 'delivery'])
            ->assertJsonPath('package.outlets', null);

        $this->platform()->postJson('/api/v1/platform/packages', ['name' => 'Nothing', 'modules' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('modules');

        $this->platform()->postJson('/api/v1/platform/packages', ['name' => 'Bogus', 'modules' => ['teleport']])
            ->assertUnprocessable();
    }

    #[Test]
    public function the_platform_token_is_required(): void
    {
        $this->getJson('/api/v1/platform/packages')->assertUnauthorized();
    }

    #[Test]
    public function assigning_a_package_replaces_the_tiers_modules_and_outlets(): void
    {
        $package = $this->cloudKitchen();

        $this->assign($package)
            ->assertOk()
            ->assertJsonPath('tenant.plan', 'starter')
            ->assertJsonPath('tenant.package.name', 'Cloud kitchen')
            ->assertJsonPath('tenant.outlet_limit', 2);

        $tenant = $this->tenant->fresh();
        $this->assertTrue($tenant->hasModule(Modules::DELIVERY));
        $this->assertFalse($tenant->hasModule(Modules::CRM));

        // The owner's role picked up the Delivery permissions.
        $this->assertTrue($this->inTenant(fn () => $this->owner->fresh()->hasPermissionTo('view_delivery')));

        $this->placeDelivery()->assertCreated();

        $this->getJson("/api/v1/order/{$this->tenant->slug}")
            ->assertJsonPath('order_types', ['takeaway', 'delivery']);
    }

    #[Test]
    public function the_restaurant_is_told_its_modules(): void
    {
        $this->assign($this->cloudKitchen())->assertOk();

        Sanctum::actingAs($this->owner);

        $this->withHeader('X-Tenant-ID', $this->tenant->slug)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('modules', [...Modules::CORE, Modules::DELIVERY]);
    }

    #[Test]
    public function editing_a_package_updates_every_restaurant_on_it(): void
    {
        $package = $this->cloudKitchen();
        $this->assign($package)->assertOk();

        $this->platform()->putJson("/api/v1/platform/packages/{$package->id}", [
            'name' => 'Cloud kitchen',
            'modules' => Modules::CORE,
            'outlets' => 2,
        ])->assertOk();

        $this->assertFalse($this->tenant->fresh()->hasModule(Modules::DELIVERY));
        $this->assertFalse($this->inTenant(fn () => $this->owner->fresh()->hasPermissionTo('view_delivery')));
    }

    #[Test]
    public function removing_the_package_puts_the_tier_back(): void
    {
        $this->assign($this->cloudKitchen())->assertOk();

        $this->assign(null)
            ->assertOk()
            ->assertJsonPath('tenant.package', null)
            ->assertJsonPath('tenant.outlet_limit', 1);

        $this->assertFalse($this->tenant->fresh()->hasModule(Modules::DELIVERY));
    }

    #[Test]
    public function a_package_in_use_cannot_be_deleted(): void
    {
        $package = $this->cloudKitchen();
        $this->assign($package)->assertOk();

        $this->platform()->deleteJson("/api/v1/platform/packages/{$package->id}")
            ->assertStatus(409)
            ->assertJsonPath('error', 'package_in_use');

        $this->assign(null)->assertOk();

        $this->platform()->deleteJson("/api/v1/platform/packages/{$package->id}")->assertNoContent();
    }

    #[Test]
    public function changing_the_plan_on_subscribe_resyncs_roles(): void
    {
        $this->platform()->postJson("/api/v1/platform/tenants/{$this->tenant->slug}/subscribe", [
            'cycle' => 'monthly',
            'plan' => 'growth',
        ])->assertOk();

        $this->assertTrue($this->inTenant(fn () => $this->owner->fresh()->hasPermissionTo('view_delivery')));
    }
}
