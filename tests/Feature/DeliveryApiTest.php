<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Location;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeliveryApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $staff;
    private Location $location;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'delivery-test', 'plan' => 'enterprise']);

        app(TenantContext::class)->runFor($this->tenant, function () {
            $this->location = Location::create(['name' => 'Main Outlet']);
            $customer = Customer::create(['name' => 'John Doe', 'phone' => '01711000000']);
            $this->order = Order::create([
                'location_id' => $this->location->id,
                'customer_id' => $customer->id,
                'order_type' => 'delivery',
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'subtotal' => 500,
                'total' => 500,
                'delivery_address' => '123 Test Street',
            ]);
        });

        $this->staff = User::factory()->forTenant($this->tenant)->create([
            'location_id' => $this->location->id,
        ]);
        Sanctum::actingAs($this->staff);
    }

    public function test_deliveries_index_requires_auth(): void
    {
        // Without auth token
        $this->app['auth']->forgetGuards();
        $response = $this->getJson('/api/v1/deliveries');
        $response->assertUnauthorized();
    }

    public function test_deliveries_index_returns_list_for_authenticated_tenant(): void
    {
        $response = $this->getJson('/api/v1/deliveries');
        $response->assertOk();
    }

    public function test_store_creates_delivery_with_floating_rider_null_location(): void
    {
        // A rider with location_id = null (all-branch floating driver)
        $floatingRider = User::factory()->forTenant($this->tenant)->create([
            'name' => 'Floating Driver',
            'location_id' => null,
        ]);

        $response = $this->postJson('/api/v1/deliveries', [
            'order_id' => $this->order->id,
            'rider_id' => $floatingRider->id,
            'address' => '123 Test Street',
            'delivery_charge' => 60.00,
            'status' => 'Pending',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('deliveries', [
            'order_id' => $this->order->id,
            'rider_id' => $floatingRider->id,
            'status' => 'Pending',
        ]);
    }

    public function test_store_creates_delivery_with_branch_rider(): void
    {
        $branchRider = User::factory()->forTenant($this->tenant)->create([
            'name' => 'Branch Driver',
            'location_id' => $this->location->id,
        ]);

        $response = $this->postJson('/api/v1/deliveries', [
            'order_id' => $this->order->id,
            'rider_id' => $branchRider->id,
            'address' => '456 Branch Road',
            'delivery_charge' => 50.00,
            'status' => 'Assigned',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('deliveries', [
            'order_id' => $this->order->id,
            'rider_id' => $branchRider->id,
            'status' => 'Assigned',
        ]);
    }

    public function test_store_creates_delivery_without_rider(): void
    {
        $response = $this->postJson('/api/v1/deliveries', [
            'order_id' => $this->order->id,
            'rider_id' => null,
            'address' => '789 Unassigned Way',
            'delivery_charge' => 40.00,
            'status' => 'Pending',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('deliveries', [
            'order_id' => $this->order->id,
            'rider_id' => null,
        ]);
    }

    public function test_show_and_update_delivery_with_rider(): void
    {
        $delivery = app(TenantContext::class)->runFor($this->tenant, function () {
            return Delivery::create([
                'order_id' => $this->order->id,
                'rider_id' => null,
                'address' => 'Initial Address',
                'delivery_charge' => 50.00,
                'status' => 'Pending',
            ]);
        });

        // Show
        $showRes = $this->getJson("/api/v1/deliveries/{$delivery->id}");
        $showRes->assertOk();
        $this->assertSame($delivery->id, $showRes->json('id'));

        // Update with rider
        $rider = User::factory()->forTenant($this->tenant)->create(['location_id' => null]);
        $updateRes = $this->putJson("/api/v1/deliveries/{$delivery->id}", [
            'rider_id' => $rider->id,
            'status' => 'In Transit',
        ]);

        $updateRes->assertOk();
        $this->assertDatabaseHas('deliveries', [
            'id' => $delivery->id,
            'rider_id' => $rider->id,
            'status' => 'In Transit',
        ]);
    }

    public function test_destroy_removes_delivery(): void
    {
        $delivery = app(TenantContext::class)->runFor($this->tenant, function () {
            return Delivery::create([
                'order_id' => $this->order->id,
                'rider_id' => null,
                'address' => 'To be deleted',
                'delivery_charge' => 0,
                'status' => 'Pending',
            ]);
        });

        $deleteRes = $this->deleteJson("/api/v1/deliveries/{$delivery->id}");
        $deleteRes->assertNoContent();

        $this->assertDatabaseMissing('deliveries', ['id' => $delivery->id]);
    }
}
