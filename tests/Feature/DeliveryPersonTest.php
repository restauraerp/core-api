<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DeliveryPersonService;
use App\Support\Orders\OrderFlow;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeliveryPersonTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('deliver_orders', 'web');
        Permission::findOrCreate('view_pos', 'web');
        Permission::findOrCreate('create_pos_order', 'web');
        Permission::findOrCreate('edit_order', 'web');

        $this->tenant = Tenant::factory()->create([
            'slug' => 'delivery-person-test',
            'plan' => 'enterprise',
        ]);

        app(TenantContext::class)->set($this->tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->location = Location::create([
            'name' => 'Main Branch',
            'slug' => 'main-branch',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->forTenant($this->tenant)->create([
            'name' => 'Admin User',
            'email' => 'admin@delivery-test.org',
            'location_id' => $this->location->id,
        ]);

        Sanctum::actingAs($this->admin);
    }

    public function test_delivery_persons_endpoint_requires_auth(): void
    {
        // Unauthenticated request
        Auth::forgetGuards();

        $response = $this->getJson('/api/v1/delivery-persons');
        $response->assertStatus(401);
    }

    public function test_delivery_persons_endpoint_returns_only_delivery_qualified_users(): void
    {
        // 1. Dedicated Rider with rider role
        $riderRole = Role::create([
            'name' => 'rider',
            'guard_name' => 'web',
            'tenant_id' => $this->tenant->id,
        ]);
        $riderRole->givePermissionTo('deliver_orders');

        $riderUser = User::factory()->forTenant($this->tenant)->create([
            'name' => 'Faisal Rider',
            'email' => 'faisal@rider.test',
        ]);
        $riderUser->assignRole($riderRole);

        // 2. Waiter with direct deliver_orders permission
        $waiterWithDelivery = User::factory()->forTenant($this->tenant)->create([
            'name' => 'Tofazzal Waiter Driver',
            'email' => 'tofazzal@waiter.test',
        ]);
        $waiterWithDelivery->givePermissionTo('deliver_orders');

        // 3. Normal Waiter without delivery permission
        $waiterRole = Role::create([
            'name' => 'waiter',
            'guard_name' => 'web',
            'tenant_id' => $this->tenant->id,
        ]);
        $normalWaiter = User::factory()->forTenant($this->tenant)->create([
            'name' => 'Normal Waiter',
            'email' => 'normal@waiter.test',
        ]);
        $normalWaiter->assignRole($waiterRole);

        // 4. Chef without delivery permission
        User::factory()->forTenant($this->tenant)->create([
            'name' => 'Chef Mario',
            'email' => 'mario@chef.test',
        ]);

        $response = $this->getJson('/api/v1/delivery-persons');

        $response->assertOk();
        $data = $response->json();

        $ids = array_column($data, 'id');
        $this->assertContains($riderUser->id, $ids);
        $this->assertContains($waiterWithDelivery->id, $ids);
        $this->assertNotContains($normalWaiter->id, $ids);
        $this->assertCount(2, $data);
    }

    public function test_delivery_persons_filters_by_location_id_including_floating_riders(): void
    {
        $branch2 = Location::create(['name' => 'Branch 2', 'slug' => 'branch-2', 'is_active' => true]);

        $riderRole = Role::create([
            'name' => 'rider',
            'guard_name' => 'web',
            'tenant_id' => $this->tenant->id,
        ]);

        // Floating rider (location_id null)
        $floating = User::factory()->forTenant($this->tenant)->create([
            'name' => 'Floating Rider',
            'location_id' => null,
        ]);
        $floating->assignRole($riderRole);

        // Branch 1 rider
        $branch1Rider = User::factory()->forTenant($this->tenant)->create([
            'name' => 'Branch 1 Rider',
            'location_id' => $this->location->id,
        ]);
        $branch1Rider->assignRole($riderRole);

        // Branch 2 rider
        $branch2Rider = User::factory()->forTenant($this->tenant)->create([
            'name' => 'Branch 2 Rider',
            'location_id' => $branch2->id,
        ]);
        $branch2Rider->assignRole($riderRole);

        // Query for Branch 1: should return Floating + Branch 1, NOT Branch 2
        $response = $this->getJson("/api/v1/delivery-persons?location_id={$this->location->id}");
        $response->assertOk();

        $ids = array_column($response->json(), 'id');
        $this->assertContains($floating->id, $ids);
        $this->assertContains($branch1Rider->id, $ids);
        $this->assertNotContains($branch2Rider->id, $ids);
    }

    public function test_delivery_persons_results_are_cached(): void
    {
        $cacheKey = DeliveryPersonService::cacheKey($this->tenant->id);
        Cache::forget($cacheKey);

        $this->assertFalse(Cache::has($cacheKey));

        // First call caches results
        $results = DeliveryPersonService::getForTenant($this->tenant->id);
        $this->assertTrue(Cache::has($cacheKey));

        // Verify content from cache
        $cached = Cache::get($cacheKey);
        $this->assertCount($results->count(), $cached);
    }

    public function test_cache_is_flushed_when_user_is_created_updated_or_deleted(): void
    {
        $cacheKey = DeliveryPersonService::cacheKey($this->tenant->id);

        // Prime cache
        DeliveryPersonService::getForTenant($this->tenant->id);
        $this->assertTrue(Cache::has($cacheKey));

        // 1. User created
        $newUser = User::factory()->forTenant($this->tenant)->create([
            'name' => 'Newly Created Staff',
        ]);
        $this->assertFalse(Cache::has($cacheKey), 'Cache should be flushed on User creation');

        // Prime cache again
        DeliveryPersonService::getForTenant($this->tenant->id);
        $this->assertTrue(Cache::has($cacheKey));

        // 2. User updated
        $newUser->update(['name' => 'Updated Staff Name']);
        $this->assertFalse(Cache::has($cacheKey), 'Cache should be flushed on User update');

        // Prime cache again
        DeliveryPersonService::getForTenant($this->tenant->id);
        $this->assertTrue(Cache::has($cacheKey));

        // 3. User deleted
        $newUser->delete();
        $this->assertFalse(Cache::has($cacheKey), 'Cache should be flushed on User deletion');
    }

    public function test_cache_is_flushed_when_role_is_created_updated_or_deleted(): void
    {
        $cacheKey = DeliveryPersonService::cacheKey($this->tenant->id);

        // Prime cache
        DeliveryPersonService::getForTenant($this->tenant->id);
        $this->assertTrue(Cache::has($cacheKey));

        // 1. Role created
        $role = Role::create([
            'name' => 'custom_driver',
            'guard_name' => 'web',
            'tenant_id' => $this->tenant->id,
        ]);
        $this->assertFalse(Cache::has($cacheKey), 'Cache should be flushed on Role creation');

        // Prime cache again
        DeliveryPersonService::getForTenant($this->tenant->id);
        $this->assertTrue(Cache::has($cacheKey));

        // 2. Role updated / permissions synced
        $this->putJson("/api/v1/roles/{$role->id}", [
            'name' => 'custom_driver_renamed',
            'permissions' => ['deliver_orders'],
        ])->assertOk();
        $this->assertFalse(Cache::has($cacheKey), 'Cache should be flushed on Role update');

        // Prime cache again
        DeliveryPersonService::getForTenant($this->tenant->id);
        $this->assertTrue(Cache::has($cacheKey));

        // 3. Role deleted
        $this->deleteJson("/api/v1/roles/{$role->id}")->assertStatus(204);
        $this->assertFalse(Cache::has($cacheKey), 'Cache should be flushed on Role deletion');
    }

    public function test_delivery_order_creation_sets_served_by_and_creates_delivery_record(): void
    {
        $product = Product::create([
            'name' => 'Biryani Dish',
            'price' => 200,
            'type' => 'food',
            'is_active' => true,
        ]);

        $riderRole = Role::create([
            'name' => 'rider',
            'guard_name' => 'web',
            'tenant_id' => $this->tenant->id,
        ]);
        $rider = User::factory()->forTenant($this->tenant)->create([
            'name' => 'Delivery Rider Kabir',
        ]);
        $rider->assignRole($riderRole);

        $payload = [
            'location_id' => $this->location->id,
            'order_type' => OrderFlow::DELIVERY,
            'subtotal' => 200,
            'discount_amount' => 0,
            'delivery_charge' => 50,
            'served_by_user_id' => $rider->id,
            'delivery_address' => 'House 12, Road 4, Dhanmondi',
            'items' => [
                ['product_id' => $product->id, 'qty' => 1, 'price' => 200],
            ],
        ];

        $response = $this->postJson('/api/v1/orders', $payload);
        $response->assertStatus(201);

        $orderId = $response->json('id');
        $order = Order::find($orderId);

        $this->assertNotNull($order);
        $this->assertEquals($rider->id, $order->served_by_user_id);

        // Assert Delivery record was automatically created
        $delivery = $order->deliveries()->first();
        $this->assertNotNull($delivery, 'Delivery record should be automatically created for delivery order');
        $this->assertEquals($rider->id, $delivery->rider_id);
        $this->assertEquals('House 12, Road 4, Dhanmondi', $delivery->address);
        $this->assertEquals(50.00, (float) $delivery->delivery_charge);
        $this->assertEquals('Pending', $delivery->status);
    }

    public function test_self_heals_when_corrupted_or_incomplete_class_is_in_cache(): void
    {
        $cacheKey = DeliveryPersonService::cacheKey($this->tenant->id);

        // Simulate a corrupted serialized object in cache that results in __PHP_Incomplete_Class
        $incomplete = unserialize('O:14:"UndefinedClass":0:{}');
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $incomplete);

        Cache::put($cacheKey, $incomplete, 3600);
        $this->assertTrue(Cache::has($cacheKey));

        // Call getForTenant(): it should detect the corrupted cache, forget it, and re-fetch clean data
        $riders = DeliveryPersonService::getForTenant($this->tenant->id);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $riders);
        // And cache should now hold clean array data
        $freshCache = Cache::get($cacheKey);
        $this->assertIsArray($freshCache);
        $this->assertNotInstanceOf(\__PHP_Incomplete_Class::class, $freshCache);
    }
}
