<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customers ordering for themselves, with no account.
 *
 * The only way in is the restaurant's online order link, `order/{slug}`, which
 * prices the order from the product table. The old `storefront/orders` route
 * went straight to OrderController::store and trusted whatever price, discount
 * and payment method it was posted - anyone could order at ৳1 and mark it paid.
 */
class PublicOrderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'online-test', 'plan' => 'enterprise']);

        [$this->location, $this->product] = app(TenantContext::class)->runFor($this->tenant, fn () => [
            Location::create(['name' => 'Main', 'slug' => 'main', 'is_active' => true]),
            Product::create(['name' => 'Biryani', 'price' => 500, 'type' => 'food', 'is_active' => true]),
        ]);
    }

    private function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'order_type' => 'takeaway',
            'name' => 'Rahim',
            'phone' => '01711223344',
            'items' => [['product_id' => $this->product->id, 'qty' => 2]],
        ], $overrides);
    }

    private function orders()
    {
        return app(TenantContext::class)->runFor($this->tenant, fn () => Order::all());
    }

    public function test_the_old_storefront_order_route_is_gone(): void
    {
        $this->withHeader('X-Tenant-ID', 'online-test')
            ->postJson('/api/v1/storefront/orders', [
                'location_id' => $this->location->id,
                'order_type' => 'delivery',
                'subtotal' => 1,
                'total' => 1,
                'payment_method' => 'cash',
                'items' => [['product_id' => $this->product->id, 'qty' => 2, 'price' => 0.5]],
            ])
            ->assertNotFound();

        $this->assertCount(0, $this->orders());
    }

    public function test_the_menu_is_public(): void
    {
        $this->getJson('/api/v1/order/online-test')
            ->assertOk()
            ->assertJsonPath('accepting_orders', true)
            ->assertJsonPath('products.0.name', 'Biryani');
    }

    public function test_an_unknown_restaurant_is_not_found(): void
    {
        $this->getJson('/api/v1/order/no-such-place')->assertNotFound();
        $this->postJson('/api/v1/order/no-such-place', $this->orderPayload())->assertNotFound();
    }

    public function test_an_order_is_priced_by_the_server_and_left_unpaid(): void
    {
        $this->postJson('/api/v1/order/online-test', $this->orderPayload([
            // None of these are the customer's to set, and all must be ignored.
            'subtotal' => 1,
            'total' => 1,
            'discount_amount' => 999,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'items' => [['product_id' => $this->product->id, 'qty' => 2, 'price' => 0.5]],
        ]))
            ->assertCreated()
            ->assertJsonPath('subtotal', 1000)
            ->assertJsonPath('total', 1000);

        $order = $this->orders()->sole();
        $this->assertSame('online', $order->source);
        $this->assertNotSame('paid', $order->payment_status);
        $this->assertEquals(1000, $order->total);
        $this->assertEquals(0, $order->discount_amount);
    }

    public function test_a_delivery_needs_an_address(): void
    {
        $this->postJson('/api/v1/order/online-test', $this->orderPayload(['order_type' => 'delivery']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('delivery_address');
    }

    public function test_dine_in_cannot_be_ordered_online(): void
    {
        $this->postJson('/api/v1/order/online-test', $this->orderPayload(['order_type' => 'dine_in']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_type');
    }

    public function test_a_product_off_the_menu_is_refused(): void
    {
        app(TenantContext::class)->runFor($this->tenant, fn () => $this->product->update(['is_active' => false]));

        $this->postJson('/api/v1/order/online-test', $this->orderPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertCount(0, $this->orders());
    }

    public function test_a_suspended_restaurant_takes_no_orders(): void
    {
        $this->tenant->update(['status' => 'suspended']);

        $this->postJson('/api/v1/order/online-test', $this->orderPayload())->assertStatus(423);
        $this->assertCount(0, $this->orders());
    }

    public function test_a_cancelled_restaurant_takes_no_orders(): void
    {
        $this->tenant->update(['status' => 'cancelled']);

        $this->postJson('/api/v1/order/online-test', $this->orderPayload())->assertStatus(423);
        $this->assertCount(0, $this->orders());
    }

    private function placeOnline(): array
    {
        return $this->postJson('/api/v1/order/online-test', $this->orderPayload())
            ->assertCreated()
            ->json();
    }

    public function test_a_customer_can_look_up_their_order_with_its_key(): void
    {
        $placed = $this->placeOnline();

        $this->postJson('/api/v1/order/online-test/my-orders', [
            'orders' => [['id' => $placed['order_id'], 'key' => $placed['tracking_key']]],
        ])
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.id', $placed['order_id'])
            ->assertJsonPath('orders.0.total', 1000)
            ->assertJsonPath('orders.0.items.0.name', 'Biryani')
            ->assertJsonPath('orders.0.items.0.qty', 2);
    }

    public function test_an_order_id_without_its_key_shows_nothing(): void
    {
        $placed = $this->placeOnline();

        $this->postJson('/api/v1/order/online-test/my-orders', [
            'orders' => [['id' => $placed['order_id'], 'key' => str_repeat('0', 40)]],
        ])
            ->assertOk()
            ->assertJsonCount(0, 'orders');
    }

    public function test_a_key_only_works_at_its_own_restaurant(): void
    {
        $placed = $this->placeOnline();
        Tenant::factory()->create(['slug' => 'elsewhere', 'plan' => 'enterprise']);

        $this->postJson('/api/v1/order/elsewhere/my-orders', [
            'orders' => [['id' => $placed['order_id'], 'key' => $placed['tracking_key']]],
        ])
            ->assertOk()
            ->assertJsonCount(0, 'orders');
    }

    public function test_a_cancelled_order_shows_as_cancelled(): void
    {
        $placed = $this->placeOnline();
        app(TenantContext::class)->runFor($this->tenant, fn () => Order::find($placed['order_id'])->delete());

        $this->postJson('/api/v1/order/online-test/my-orders', [
            'orders' => [['id' => $placed['order_id'], 'key' => $placed['tracking_key']]],
        ])
            ->assertOk()
            ->assertJsonPath('orders.0.status', 'cancelled')
            ->assertJsonPath('orders.0.status_label', 'Cancelled')
            ->assertJsonPath('orders.0.finished', true);
    }

    public function test_a_returning_customer_can_order_again(): void
    {
        $this->postJson('/api/v1/order/online-test', $this->orderPayload())->assertCreated();
        // Same number, written differently.
        $this->postJson('/api/v1/order/online-test', $this->orderPayload(['phone' => '+8801711223344']))->assertCreated();

        $orders = $this->orders();
        $this->assertCount(2, $orders);
        $this->assertSame($orders[0]->customer_id, $orders[1]->customer_id);
    }
}
