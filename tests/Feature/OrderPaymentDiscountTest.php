<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\TaxRule;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a discounted order costs when it is paid for.
 *
 * The pay screen used to rebuild the bill from the subtotal and whatever coupon
 * was typed at the till, ignoring what the order already said had come off it.
 * A bill discounted at the POS came back up at full price, the cashier asked
 * the customer for the undiscounted amount, and confirming posted that figure
 * back - so the discount vanished from the order too, leaving lines that said
 * 200 had been taken off an order that said nothing had.
 *
 * The clients no longer post any of it. This pins the other half: a payment
 * arriving with totals attached must not restate the bill, so a till still on
 * the old build stops doing damage the day this ships.
 */
class OrderPaymentDiscountTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'pay-discount', 'plan' => 'enterprise']);

        $user = app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => User::factory()->create(['tenant_id' => $this->tenant->getKey()]),
        );

        [$this->location, $this->product] = app(TenantContext::class)->runFor($this->tenant, fn () => [
            Location::create(['name' => 'Main', 'slug' => 'main', 'is_active' => true]),
            Product::create(['name' => 'Biryani', 'price' => 500, 'type' => 'food']),
        ]);

        app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => TaxRule::create(['name' => 'VAT', 'percentage' => 10, 'is_active' => true]),
        );

        Sanctum::actingAs($user);
    }

    /**
     * 1,000 of food, 100 off the bill, 10% on what is left.
     */
    private function placeDiscountedOrder(): Order
    {
        $id = $this->postJson('/api/v1/orders', [
            'location_id' => $this->location->id,
            'order_type' => 'dine_in',
            'subtotal' => '1000.00',
            'discount_amount' => '0.00',
            'discount_type' => 'flat',
            'discount_value' => '100.00',
            'discount_reason' => 'Manager, cold starter',
            'items' => [[
                'product_id' => $this->product->id,
                'qty' => 2,
                'price' => '500.00',
            ]],
        ])->assertCreated()->json('id');

        $order = Order::withoutGlobalScopes()->findOrFail($id);

        // The bill the pay screen has to quote.
        $this->assertEquals(100.00, $order->discount_amount);
        $this->assertEquals(90.00, $order->tax_amount);
        $this->assertEquals(990.00, $order->total);

        return $order;
    }

    #[Test]
    public function paying_a_discounted_order_leaves_the_bill_alone(): void
    {
        $order = $this->placeDiscountedOrder();

        $this->putJson("/api/v1/orders/{$order->id}", [
            'payment_method' => 'cash',
            'payment_note' => 'bKash TrxID BKS8891',
        ])->assertOk();

        $order->refresh();

        $this->assertSame('paid', $order->payment_status);
        $this->assertEquals(100.00, $order->discount_amount);
        $this->assertEquals(90.00, $order->tax_amount);
        $this->assertEquals(990.00, $order->total);
    }

    #[Test]
    public function the_payment_recorded_is_what_the_order_actually_costs(): void
    {
        $order = $this->placeDiscountedOrder();

        $this->putJson("/api/v1/orders/{$order->id}", [
            'payment_method' => 'cash',
        ])->assertOk();

        $this->assertEquals(990.00, $order->payments()->sole()->amount);

        $this->assertDatabaseHas('accounting_ledgers', [
            'transaction_type' => 'order_payment',
            'reference_id' => $order->id,
            'amount' => 990.00,
        ]);
    }

    /**
     * What the old till posted, and what it did. Ignored now rather than obeyed.
     */
    #[Test]
    public function totals_posted_alongside_a_payment_are_ignored(): void
    {
        $order = $this->placeDiscountedOrder();

        $this->putJson("/api/v1/orders/{$order->id}", [
            'payment_method' => 'cash',
            'discount_id' => null,
            'discount_amount' => '0.00',
            'delivery_charge' => '0.00',
            'tax_amount' => '100.00',
            'total' => '1100.00',
        ])->assertOk();

        $order->refresh();

        $this->assertEquals(100.00, $order->discount_amount);
        $this->assertEquals(990.00, $order->total);
        $this->assertEquals(990.00, $order->payments()->sole()->amount);
    }

    /**
     * Correcting an order on its own still reprices it - the guard above is
     * about payments, not about freezing the bill.
     */
    #[Test]
    public function an_edit_without_a_payment_still_reprices_the_bill(): void
    {
        $order = $this->placeDiscountedOrder();

        $this->putJson("/api/v1/orders/{$order->id}", [
            'delivery_charge' => '60.00',
        ])->assertOk();

        $order->refresh();

        $this->assertEquals(100.00, $order->discount_amount);
        $this->assertEquals(1050.00, $order->total);
    }
}
