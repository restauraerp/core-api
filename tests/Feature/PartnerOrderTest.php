<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Order;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Orders that arrive through a delivery aggregator.
 *
 * The partner sends the order, the restaurant cooks it, the partner keeps a
 * quarter and pays the rest over weeks later. So the sale is worth less than
 * the bill says, and the money is owed by the partner rather than the diner.
 */
class PartnerOrderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Partner Restaurant',
            'slug' => 'partner-restaurant',
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

    private function headers(): array
    {
        return ['X-Tenant-ID' => $this->tenant->slug];
    }

    private function partner(float $rate = 25): Partner
    {
        return $this->inTenant(fn () => Partner::create([
            'name' => 'Aggregator '.$rate,
            'commission_rate' => $rate,
        ]));
    }

    private function placeOrder(?Partner $partner, float $amount = 1000): int
    {
        $ids = $this->inTenant(function () {
            $category = ProductCategory::create(['name' => 'Mains']);
            $product = Product::create(['category_id' => $category->getKey(), 'name' => 'Biryani', 'price' => 500]);

            return [Location::query()->firstOrFail()->getKey(), $product->getKey()];
        });

        return $this->postJson('/api/v1/orders', [
            'location_id' => $ids[0],
            'order_type' => 'delivery',
            'partner_id' => $partner?->getKey(),
            'subtotal' => $amount,
            'discount_amount' => 0,
            'items' => [['product_id' => $ids[1], 'qty' => 1, 'price' => $amount]],
        ], $this->headers())->assertCreated()->json('id');
    }

    #[Test]
    public function the_partners_cut_is_worked_out_from_the_partners_own_rate(): void
    {
        $partner = $this->partner(25);

        $order = Order::withoutGlobalScopes()->findOrFail($this->placeOrder($partner, 1000));

        $this->assertEquals(25.00, $order->partner_commission_rate);
        $this->assertEquals(250.00, $order->partner_commission_amount);
        $this->assertSame(750.0, $order->partner_net_amount);
    }

    /**
     * The till does not get to decide what a partner keeps. A commission posted
     * by a client is a number the client chose - the same reasoning that moved
     * tax off the POS and onto the server.
     */
    #[Test]
    public function a_commission_sent_by_the_client_is_ignored(): void
    {
        $partner = $this->partner(25);

        $ids = $this->inTenant(function () {
            $category = ProductCategory::create(['name' => 'Mains']);
            $product = Product::create(['category_id' => $category->getKey(), 'name' => 'Biryani', 'price' => 500]);

            return [Location::query()->firstOrFail()->getKey(), $product->getKey()];
        });

        $id = $this->postJson('/api/v1/orders', [
            'location_id' => $ids[0],
            'order_type' => 'delivery',
            'partner_id' => $partner->getKey(),
            'partner_commission_rate' => 1,
            'partner_commission_amount' => 5,
            'subtotal' => 1000,
            'discount_amount' => 0,
            'items' => [['product_id' => $ids[1], 'qty' => 1, 'price' => 1000]],
        ], $this->headers())->assertCreated()->json('id');

        $order = Order::withoutGlobalScopes()->findOrFail($id);

        $this->assertEquals(25.00, $order->partner_commission_rate);
        $this->assertEquals(250.00, $order->partner_commission_amount);
    }

    /**
     * Rates get renegotiated, and history must not restate itself when they do.
     */
    #[Test]
    public function changing_the_rate_leaves_past_orders_alone(): void
    {
        $partner = $this->partner(25);
        $order = Order::withoutGlobalScopes()->findOrFail($this->placeOrder($partner, 1000));

        $this->putJson("/api/v1/partners/{$partner->getKey()}", [
            'name' => $partner->name,
            'commission_rate' => 10,
        ], $this->headers())->assertOk();

        $this->assertEquals(250.00, $order->fresh()->partner_commission_amount);
    }

    #[Test]
    public function an_ordinary_order_keeps_its_whole_total(): void
    {
        $order = Order::withoutGlobalScopes()->findOrFail($this->placeOrder(null, 800));

        $this->assertNull($order->partner_id);
        $this->assertNull($order->partner_commission_amount);
        $this->assertSame(800.0, $order->partner_net_amount);
    }

    #[Test]
    public function the_report_separates_what_was_billed_from_what_was_earned(): void
    {
        $partner = $this->partner(25);
        $this->placeOrder($partner, 1000);
        $this->placeOrder($partner, 400);

        $row = $this->getJson('/api/v1/reports/partners', $this->headers())
            ->assertOk()
            ->json('partners.0');

        $this->assertSame(2, $row['orders_count']);
        $this->assertEqualsWithDelta(1400.0, $row['gross'], 0.001);
        $this->assertEqualsWithDelta(350.0, $row['commission'], 0.001);
        $this->assertEqualsWithDelta(1050.0, $row['net'], 0.001);
        $this->assertEqualsWithDelta(1050.0, $row['outstanding'], 0.001);
    }

    #[Test]
    public function a_payout_reduces_what_the_partner_owes(): void
    {
        $partner = $this->partner(25);
        $this->placeOrder($partner, 1000);

        $this->postJson('/api/v1/partner-payouts', [
            'partner_id' => $partner->getKey(),
            'amount' => 300,
            'received_on' => '2026-08-19',
            'reference' => 'TRF-1',
        ], $this->headers())->assertCreated();

        $row = $this->getJson('/api/v1/reports/partners', $this->headers())->json('partners.0');

        $this->assertEqualsWithDelta(750.0, $row['earned_to_date'], 0.001);
        $this->assertEqualsWithDelta(300.0, $row['paid_to_date'], 0.001);
        $this->assertEqualsWithDelta(450.0, $row['outstanding'], 0.001);
    }

    /**
     * Money in the door should be visible in the books, and against no outlet:
     * an aggregator settles a fortnight of trading across every branch in one
     * transfer, and splitting it would invent a breakdown it does not carry.
     */
    #[Test]
    public function a_payout_is_posted_to_the_books_without_an_outlet(): void
    {
        $partner = $this->partner(25);

        $this->postJson('/api/v1/partner-payouts', [
            'partner_id' => $partner->getKey(),
            'amount' => 500,
            'received_on' => '2026-08-19',
        ], $this->headers())->assertCreated();

        $this->assertDatabaseHas('accounting_ledgers', [
            'transaction_type' => 'partner_payout',
            'amount' => 500,
            'location_id' => null,
        ]);
    }

    /**
     * Deleting a partner would detach its orders from the money they earned, so
     * one that has traded is switched off instead - the rule outlets follow.
     */
    #[Test]
    public function a_partner_that_has_sent_orders_cannot_be_deleted(): void
    {
        $partner = $this->partner(25);
        $this->placeOrder($partner, 1000);

        $this->deleteJson("/api/v1/partners/{$partner->getKey()}", [], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('error', 'partner_has_orders');

        $this->assertDatabaseHas('partners', ['id' => $partner->getKey()]);
    }

    #[Test]
    public function a_partner_that_never_traded_can_be_deleted(): void
    {
        $partner = $this->partner(25);

        $this->deleteJson("/api/v1/partners/{$partner->getKey()}", [], $this->headers())
            ->assertNoContent();
    }

    #[Test]
    public function an_order_cannot_name_another_restaurants_partner(): void
    {
        $other = app(TenantProvisioner::class)->create([
            'name' => 'Somebody Else', 'slug' => 'somebody-else-partner',
            'plan' => 'enterprise', 'status' => 'active',
        ]);

        $stranger = app(TenantContext::class)->runFor($other, fn () => Partner::create([
            'name' => 'Their Aggregator', 'commission_rate' => 25,
        ]));

        $ids = $this->inTenant(function () {
            $category = ProductCategory::create(['name' => 'Mains']);
            $product = Product::create(['category_id' => $category->getKey(), 'name' => 'Biryani', 'price' => 500]);

            return [Location::query()->firstOrFail()->getKey(), $product->getKey()];
        });

        $this->postJson('/api/v1/orders', [
            'location_id' => $ids[0],
            'order_type' => 'delivery',
            'partner_id' => $stranger->getKey(),
            'subtotal' => 1000,
            'discount_amount' => 0,
            'items' => [['product_id' => $ids[1], 'qty' => 1, 'price' => 1000]],
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('partner_id');
    }
}
