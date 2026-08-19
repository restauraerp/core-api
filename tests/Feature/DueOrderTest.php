<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Orders\OrderFlow;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Orders the restaurant has agreed to be paid for later.
 *
 * The case is a resident hotel guest charging dinner to their room: the food
 * goes out, the money comes later, and in between the restaurant is owed
 * something it has to be able to find again.
 */
class DueOrderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Due Restaurant',
            'slug' => 'due-restaurant',
            'plan' => 'enterprise',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->inTenant(
            fn () => User::factory()->create(['tenant_id' => $this->tenant->getKey()]),
        ));
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function customer(): Customer
    {
        return $this->inTenant(fn () => Customer::create([
            'name' => 'Hotel Guest',
            'phone' => '01712345678',
        ]));
    }

    private function placeOrder(?Customer $customer, float $total = 1000): Order
    {
        return $this->inTenant(function () use ($customer, $total) {
            $category = ProductCategory::create(['name' => 'Mains']);
            $product = Product::create(['category_id' => $category->getKey(), 'name' => 'Biryani', 'price' => $total]);

            $order = Order::create([
                'location_id' => Location::query()->firstOrFail()->getKey(),
                'customer_id' => $customer?->getKey(),
                'order_type' => OrderFlow::DINE_IN,
                'status' => OrderFlow::SERVED,
                'payment_status' => Order::PAYMENT_UNPAID,
                'subtotal' => $total,
                'total' => $total,
            ]);

            $order->items()->create(['product_id' => $product->getKey(), 'quantity' => 1, 'price' => $total]);

            return $order;
        });
    }

    private function headers(): array
    {
        return ['X-Tenant-ID' => $this->tenant->slug];
    }

    #[Test]
    public function a_debt_cannot_be_left_on_nobody(): void
    {
        $order = $this->placeOrder(null);

        $this->postJson("/api/v1/orders/{$order->getKey()}/due", ['due_note' => 'Room 402'], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');

        $this->assertSame(Order::PAYMENT_UNPAID, $order->fresh()->payment_status);
    }

    #[Test]
    public function the_arrangement_has_to_be_written_down(): void
    {
        $order = $this->placeOrder($this->customer());

        $this->postJson("/api/v1/orders/{$order->getKey()}/due", [], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_note');
    }

    #[Test]
    public function an_order_already_paid_for_owes_nothing(): void
    {
        $order = $this->placeOrder($this->customer());
        $order->update(['payment_status' => Order::PAYMENT_PAID]);

        $this->postJson("/api/v1/orders/{$order->getKey()}/due", ['due_note' => 'Room 402'], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_status');
    }

    #[Test]
    public function putting_an_order_on_account_records_the_arrangement(): void
    {
        $order = $this->placeOrder($this->customer());

        $this->postJson("/api/v1/orders/{$order->getKey()}/due", [
            'due_note' => 'Room 402, checks out Sunday',
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('payment_status', Order::PAYMENT_DUE)
            ->assertJsonPath('due_note', 'Room 402, checks out Sunday')
            // Compared numerically: JSON has no int/float distinction, so a
            // whole-number amount arrives as 1000 rather than 1000.0.
            ->assertJsonPath('amount_outstanding', fn ($value) => (float) $value === 1000.0);
    }

    /**
     * The reason scopeActive() had to change. A due order is finished on the
     * floor - the food has gone out and been served - and deliberately not paid
     * for. Under the old rule it matched "not paid" and sat in the live view
     * forever, filling the one screen that tells staff what still needs doing
     * with orders nobody could ever clear.
     */
    #[Test]
    public function a_finished_due_order_leaves_the_floor_but_appears_under_due(): void
    {
        $order = $this->placeOrder($this->customer());

        $this->postJson("/api/v1/orders/{$order->getKey()}/due", ['due_note' => 'House account'], $this->headers())
            ->assertOk();

        $active = $this->getJson('/api/v1/orders?active_only=1&nopaginate=1', $this->headers())->json();
        $due = $this->getJson('/api/v1/orders?due_only=1&nopaginate=1', $this->headers())->json();

        $this->assertNotContains($order->getKey(), array_column($active, 'id'));
        $this->assertContains($order->getKey(), array_column($due, 'id'));
    }

    /**
     * ...but one the kitchen has not finished is still the kitchen's problem,
     * however it is going to be paid for.
     */
    #[Test]
    public function a_due_order_still_being_cooked_stays_on_the_floor(): void
    {
        $order = $this->placeOrder($this->customer());
        $order->update(['status' => OrderFlow::COOKING]);

        $this->postJson("/api/v1/orders/{$order->getKey()}/due", ['due_note' => 'House account'], $this->headers())
            ->assertOk();

        $active = $this->getJson('/api/v1/orders?active_only=1&nopaginate=1', $this->headers())->json();

        $this->assertContains($order->getKey(), array_column($active, 'id'));
    }

    #[Test]
    public function a_due_order_is_not_a_completed_one(): void
    {
        $order = $this->placeOrder($this->customer());

        $this->postJson("/api/v1/orders/{$order->getKey()}/due", ['due_note' => 'House account'], $this->headers());

        $completed = $this->getJson('/api/v1/orders?completed_only=1', $this->headers())->json('data');

        $this->assertNotContains($order->getKey(), array_column($completed, 'id'));
    }

    #[Test]
    public function a_tab_can_be_settled_in_instalments(): void
    {
        $order = $this->placeOrder($this->customer());
        $this->postJson("/api/v1/orders/{$order->getKey()}/due", ['due_note' => 'Room 402'], $this->headers());

        $this->postJson("/api/v1/orders/{$order->getKey()}/settle", [
            'amount' => 400, 'method' => 'cash', 'note' => 'paid at the counter',
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('payment_status', Order::PAYMENT_DUE)
            ->assertJsonPath('amount_outstanding', fn ($value) => (float) $value === 600.0);

        $this->postJson("/api/v1/orders/{$order->getKey()}/settle", [
            'amount' => 600, 'method' => 'bkash', 'note' => 'TrxID BKS8891',
        ], $this->headers())
            ->assertOk()
            // Closes itself the moment the payments cover it, so nothing has to
            // remember to.
            ->assertJsonPath('payment_status', Order::PAYMENT_PAID)
            ->assertJsonPath('amount_outstanding', fn ($value) => (float) $value === 0.0);

        $this->assertDatabaseCount('payments', 2);
    }

    /**
     * A cashier typing 5000 against a 500 tab has made a mistake, and quietly
     * recording 500 would hide it.
     */
    #[Test]
    public function more_than_is_owed_is_refused(): void
    {
        $order = $this->placeOrder($this->customer());
        $this->postJson("/api/v1/orders/{$order->getKey()}/due", ['due_note' => 'Room 402'], $this->headers());

        $this->postJson("/api/v1/orders/{$order->getKey()}/settle", [
            'amount' => 5000, 'method' => 'cash',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('payments', 0);
    }

    #[Test]
    public function each_settlement_is_posted_to_the_books(): void
    {
        $order = $this->placeOrder($this->customer());
        $this->postJson("/api/v1/orders/{$order->getKey()}/due", ['due_note' => 'Room 402'], $this->headers());

        $this->postJson("/api/v1/orders/{$order->getKey()}/settle", ['amount' => 400, 'method' => 'cash'], $this->headers());
        $this->postJson("/api/v1/orders/{$order->getKey()}/settle", ['amount' => 600, 'method' => 'cash'], $this->headers());

        // Two movements of money, two entries - a tab paid in instalments
        // should show when each part arrived.
        $this->assertDatabaseCount('accounting_ledgers', 2);
    }

    #[Test]
    public function a_customers_record_shows_what_they_owe(): void
    {
        $customer = $this->customer();

        $first = $this->placeOrder($customer, 1000);
        $second = $this->placeOrder($customer, 500);

        foreach ([$first, $second] as $order) {
            $this->postJson("/api/v1/orders/{$order->getKey()}/due", ['due_note' => 'Room 402'], $this->headers());
        }

        $this->postJson("/api/v1/orders/{$first->getKey()}/settle", ['amount' => 250, 'method' => 'cash'], $this->headers());

        $this->getJson("/api/v1/customers/{$customer->getKey()}/orders", $this->headers())
            ->assertOk()
            ->assertJsonPath('outstanding.orders', 2)
            // 750 still owed on the first, 500 on the second.
            ->assertJsonPath('outstanding.amount', fn ($value) => (float) $value === 1250.0);
    }
}
