<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Orders\OrderFlow;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Where an order starts and how it moves.
 *
 * Two facts decide the run: whether anything on the order has to be prepared,
 * and whether it is due yet. Everything here is a question a restaurant would
 * recognise - does this reach the kitchen, and can this jump the queue?
 */
class OrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    private Product $dish;

    private Product $bottle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'flow-test']);

        app(TenantContext::class)->runFor($this->tenant, function () {
            $this->location = Location::create(['name' => 'Main Kitchen']);
            $this->dish = Product::create(['name' => 'Chicken Biryani', 'price' => 350, 'type' => 'food', 'needs_cooking' => true, 'is_active' => 1]);
            $this->bottle = Product::create(['name' => 'Mineral Water', 'price' => 25, 'type' => 'merchandise', 'needs_cooking' => false, 'is_active' => 1]);
        });

        Sanctum::actingAs(User::factory()->forTenant($this->tenant)->create());
    }

    /** @return array<string, mixed> */
    private function payload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'location_id' => $this->location->id,
            'order_type' => OrderFlow::DINE_IN,
            'subtotal' => $product->price,
            'discount_amount' => 0,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'price' => $product->price]],
        ], $overrides);
    }

    private function place(Product $product, array $overrides = []): array
    {
        $response = $this->postJson('/api/v1/orders', $this->payload($product, $overrides));
        $response->assertCreated();

        return $response->json();
    }

    private function advance(int $orderId, string $status): TestResponse
    {
        return $this->putJson("/api/v1/orders/{$orderId}", ['status' => $status]);
    }

    // --- Where an order opens ------------------------------------------------

    public function test_an_order_with_something_to_cook_opens_in_the_kitchen(): void
    {
        $order = $this->place($this->dish);

        $this->assertSame(OrderFlow::COOKING, $order['status']);
        $this->assertTrue($order['needs_cooking']);
    }

    public function test_an_order_with_nothing_to_cook_opens_ready_to_serve(): void
    {
        $order = $this->place($this->bottle);

        $this->assertSame(OrderFlow::READY_TO_SERVE, $order['status']);
        $this->assertFalse($order['needs_cooking']);
    }

    public function test_one_cookable_line_is_enough_to_involve_the_kitchen(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->payload($this->dish, [
            'subtotal' => 375,
            'items' => [
                ['product_id' => $this->bottle->id, 'qty' => 1, 'price' => 25],
                ['product_id' => $this->dish->id, 'qty' => 1, 'price' => 350],
            ],
        ]));

        $response->assertCreated();
        $this->assertSame(OrderFlow::COOKING, $response->json('status'));
    }

    public function test_an_order_due_later_waits_at_pending(): void
    {
        $order = $this->place($this->dish, [
            'order_type' => OrderFlow::CATERING,
            'delivery_time' => now()->addDays(3)->toDateTimeString(),
        ]);

        $this->assertSame(OrderFlow::PENDING, $order['status']);
    }

    public function test_an_asap_order_does_not_wait(): void
    {
        $order = $this->place($this->dish, [
            'order_type' => OrderFlow::TAKEAWAY,
            'delivery_time' => null,
        ]);

        $this->assertSame(OrderFlow::COOKING, $order['status']);
    }

    public function test_a_delivery_time_already_past_does_not_wait(): void
    {
        $order = $this->place($this->dish, [
            'order_type' => OrderFlow::DELIVERY,
            'delivery_time' => now()->subHour()->toDateTimeString(),
        ]);

        $this->assertSame(OrderFlow::COOKING, $order['status']);
    }

    public function test_the_till_cannot_choose_the_opening_status(): void
    {
        $order = $this->place($this->bottle, ['status' => 'delivered']);

        $this->assertSame(OrderFlow::READY_TO_SERVE, $order['status']);
    }

    // --- The runs ------------------------------------------------------------

    public function test_dine_in_runs_cooking_to_ready_to_served(): void
    {
        $order = $this->place($this->dish);

        $this->assertSame([OrderFlow::READY_TO_SERVE], $order['next_statuses']);

        $this->advance($order['id'], OrderFlow::READY_TO_SERVE)->assertOk();
        $this->advance($order['id'], OrderFlow::SERVED)->assertOk();

        $fresh = Order::find($order['id']);
        $this->assertSame(OrderFlow::SERVED, $fresh->status);
        $this->assertSame([], $fresh->nextStatuses(), 'A served dine-in order still offers a next step.');
    }

    public function test_takeaway_ends_at_packed(): void
    {
        $order = $this->place($this->dish, ['order_type' => OrderFlow::TAKEAWAY]);

        $this->advance($order['id'], OrderFlow::READY_TO_SERVE)->assertOk();
        $this->advance($order['id'], OrderFlow::PACKED)->assertOk();

        $this->assertSame([], Order::find($order['id'])->nextStatuses());
    }

    public function test_delivery_runs_through_pick_up_to_delivered(): void
    {
        $order = $this->place($this->dish, ['order_type' => OrderFlow::DELIVERY]);

        foreach ([OrderFlow::READY_TO_SERVE, OrderFlow::PACKED, OrderFlow::PICKED_UP, OrderFlow::DELIVERED] as $stage) {
            $this->advance($order['id'], $stage)->assertOk();
            $this->assertSame($stage, Order::find($order['id'])->status);
        }
    }

    public function test_catering_runs_the_same_as_delivery(): void
    {
        $order = $this->place($this->dish, ['order_type' => OrderFlow::CATERING]);

        foreach ([OrderFlow::READY_TO_SERVE, OrderFlow::PACKED, OrderFlow::PICKED_UP, OrderFlow::DELIVERED] as $stage) {
            $this->advance($order['id'], $stage)->assertOk();
        }

        $this->assertSame(OrderFlow::DELIVERED, Order::find($order['id'])->status);
    }

    public function test_a_scheduled_order_is_started_by_hand_into_the_kitchen(): void
    {
        $order = $this->place($this->dish, [
            'order_type' => OrderFlow::DELIVERY,
            'delivery_time' => now()->addDay()->toDateTimeString(),
        ]);

        $this->assertSame([OrderFlow::COOKING], $order['next_statuses']);

        $this->advance($order['id'], OrderFlow::COOKING)->assertOk();

        $this->assertSame(OrderFlow::COOKING, Order::find($order['id'])->status);
    }

    public function test_a_scheduled_order_with_nothing_to_cook_skips_the_kitchen(): void
    {
        $order = $this->place($this->bottle, [
            'order_type' => OrderFlow::TAKEAWAY,
            'delivery_time' => now()->addDay()->toDateTimeString(),
        ]);

        $this->assertSame(OrderFlow::PENDING, $order['status']);
        // Nothing to prepare, so starting it means it is ready, not cooking.
        $this->assertSame([OrderFlow::READY_TO_SERVE], $order['next_statuses']);

        $this->advance($order['id'], OrderFlow::COOKING)->assertStatus(422);
        $this->advance($order['id'], OrderFlow::READY_TO_SERVE)->assertOk();
    }

    // --- What is refused -----------------------------------------------------

    public function test_a_stage_cannot_be_skipped(): void
    {
        $order = $this->place($this->dish, ['order_type' => OrderFlow::DELIVERY]);

        $this->advance($order['id'], OrderFlow::DELIVERED)
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(OrderFlow::COOKING, Order::find($order['id'])->status);
    }

    public function test_an_order_cannot_go_backwards(): void
    {
        $order = $this->place($this->dish);

        $this->advance($order['id'], OrderFlow::READY_TO_SERVE)->assertOk();
        $this->advance($order['id'], OrderFlow::COOKING)->assertStatus(422);
    }

    public function test_a_dine_in_order_is_never_packed(): void
    {
        $order = $this->place($this->dish);

        $this->advance($order['id'], OrderFlow::READY_TO_SERVE)->assertOk();
        $this->advance($order['id'], OrderFlow::PACKED)->assertStatus(422);
    }

    public function test_an_unknown_order_type_is_refused(): void
    {
        $this->postJson('/api/v1/orders', $this->payload($this->dish, ['order_type' => 'drive_through']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('order_type');
    }

    // --- Cancelling ----------------------------------------------------------

    public function test_an_order_can_be_cancelled_at_any_stage(): void
    {
        $order = $this->place($this->dish, ['order_type' => OrderFlow::DELIVERY]);

        $this->advance($order['id'], OrderFlow::READY_TO_SERVE)->assertOk();
        $this->advance($order['id'], OrderFlow::CANCELLED)->assertOk();

        $this->assertSame(OrderFlow::CANCELLED, Order::find($order['id'])->status);
    }

    public function test_reopening_a_cancelled_order_puts_it_back_at_the_start(): void
    {
        $order = $this->place($this->dish);

        $this->advance($order['id'], OrderFlow::CANCELLED)->assertOk();
        $this->advance($order['id'], OrderFlow::COOKING)->assertOk();

        $this->assertSame(OrderFlow::COOKING, Order::find($order['id'])->status);
    }

    // --- Old spellings -------------------------------------------------------

    public function test_the_old_status_words_still_resolve(): void
    {
        $order = $this->place($this->dish);

        // `cooked` is what `ready_to_serve` used to be called.
        $this->advance($order['id'], 'cooked')->assertOk();

        $this->assertSame(OrderFlow::READY_TO_SERVE, Order::find($order['id'])->status);
    }

    // --- What the floor and the kitchen see ----------------------------------

    public function test_an_order_with_nothing_to_cook_stays_out_of_the_kitchen_queue(): void
    {
        $this->place($this->dish);
        $this->place($this->bottle);

        // What the kiosk polls for.
        $queue = $this->getJson('/api/v1/orders?nopaginate=1&statuses=pending,cooking')->json();
        $queue = $queue['data'] ?? $queue;

        $this->assertCount(1, $queue);
        $this->assertSame(OrderFlow::COOKING, $queue[0]['status']);
    }

    public function test_a_finished_paid_order_counts_as_completed(): void
    {
        $order = $this->place($this->dish, ['payment_method' => 'cash']);

        $this->advance($order['id'], OrderFlow::READY_TO_SERVE)->assertOk();
        $this->advance($order['id'], OrderFlow::SERVED)->assertOk();

        $this->assertSame(1, Order::completed()->count());
        $this->assertSame(0, Order::active()->count());
    }

    public function test_status_labels_read_the_way_staff_speak(): void
    {
        $flow = app(OrderFlow::class);

        $this->assertSame('Ready to Serve', $flow->label(OrderFlow::READY_TO_SERVE));
        $this->assertSame('Picked Up By Delivery', $flow->label(OrderFlow::PICKED_UP));
        $this->assertSame('Ready to Serve', $flow->label('cooked'));
    }
}
