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
use Tests\TestCase;

/**
 * Tax on a sale comes from the restaurant's own rules.
 *
 * The POS used to compute `subtotal * 0.1` in the browser and post it, so every
 * order on every tenant carried 10% VAT - a rate configured nowhere, while the
 * seeded rule said 5% and was switched off. Because tax_amount is stored, the
 * error reached reporting and accounting too.
 */
class OrderTaxTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'tax-test', 'plan' => 'enterprise']);

        $user = app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => User::factory()->create(['tenant_id' => $this->tenant->getKey()]),
        );

        [$this->location, $this->product] = app(TenantContext::class)->runFor($this->tenant, fn () => [
            Location::create(['name' => 'Main', 'slug' => 'main', 'is_active' => true]),
            Product::create(['name' => 'Biryani', 'price' => 500, 'type' => 'food']),
        ]);

        Sanctum::actingAs($user);
    }

    private function placeOrder(array $overrides = [])
    {
        return $this->postJson('/api/v1/orders', array_merge([
            'location_id' => $this->location->id,
            'order_type' => 'dine_in',
            'status' => 'pending',
            'subtotal' => '1000.00',
            'discount_amount' => '0.00',
            'delivery_charge' => '0.00',
            // What the old POS posted. It must be ignored.
            'tax_amount' => '100.00',
            'total' => '1100.00',
            'items' => [[
                'product_id' => $this->product->id,
                'qty' => 2,
                'price' => '500.00',
            ]],
        ], $overrides));
    }

    private function activateTax(float $percentage): void
    {
        app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => TaxRule::create(['name' => 'VAT', 'percentage' => $percentage, 'is_active' => true]),
        );
    }

    public function test_no_active_rule_means_no_tax_even_when_the_client_posts_some(): void
    {
        $response = $this->placeOrder()->assertCreated();

        $this->assertEquals(0, (float) $response->json('tax_amount'));
        $this->assertEquals(1000, (float) $response->json('total'));
    }

    public function test_an_inactive_rule_is_not_charged(): void
    {
        // Exactly the seeded state: VAT exists at 5% but is switched off.
        app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => TaxRule::create(['name' => 'VAT', 'percentage' => 5, 'is_active' => false]),
        );

        $this->assertEquals(0, (float) $this->placeOrder()->assertCreated()->json('tax_amount'));
    }

    public function test_an_active_rule_is_applied(): void
    {
        $this->activateTax(5);

        $response = $this->placeOrder()->assertCreated();

        $this->assertEquals(50, (float) $response->json('tax_amount'));
        $this->assertEquals(1050, (float) $response->json('total'));
    }

    public function test_tax_is_charged_on_the_discounted_amount(): void
    {
        $this->activateTax(10);

        $response = $this->placeOrder([
            'discount_amount' => '200.00',
        ])->assertCreated();

        // 10% of 800, not of 1000.
        $this->assertEquals(80, (float) $response->json('tax_amount'));
        $this->assertEquals(880, (float) $response->json('total'));
    }

    public function test_delivery_charge_is_added_after_tax(): void
    {
        $this->activateTax(5);

        $response = $this->placeOrder(['delivery_charge' => '60.00'])->assertCreated();

        $this->assertEquals(50, (float) $response->json('tax_amount'));
        $this->assertEquals(1110, (float) $response->json('total'));
    }

    public function test_multiple_active_rules_are_summed_not_compounded(): void
    {
        $this->activateTax(5);
        $this->activateTax(2.5);

        // 7.5% of 1000, not 5% then 2.5% of the result.
        $this->assertEquals(75, (float) $this->placeOrder()->assertCreated()->json('tax_amount'));
    }

    public function test_a_status_change_does_not_reprice_a_historical_order(): void
    {
        // An order billed under the old hardcoded 10%. Moving it along its
        // workflow must leave what the customer actually paid alone.
        $order = app(TenantContext::class)->runFor($this->tenant, fn () => Order::create([
            'location_id' => $this->location->id,
            'order_type' => 'dine_in',
            'status' => 'pending',
            'subtotal' => 1000,
            'discount_amount' => 0,
            'tax_amount' => 100,
            'total' => 1100,
        ]));

        $this->putJson("/api/v1/orders/{$order->id}", ['status' => 'ready_to_serve'])->assertOk();

        $order->refresh();

        $this->assertEquals(100, (float) $order->tax_amount);
        $this->assertEquals(1100, (float) $order->total);
    }

    public function test_editing_the_money_on_an_order_reprices_it_under_the_current_rules(): void
    {
        $order = app(TenantContext::class)->runFor($this->tenant, fn () => Order::create([
            'location_id' => $this->location->id,
            'order_type' => 'dine_in',
            'status' => 'pending',
            'subtotal' => 1000,
            'discount_amount' => 0,
            'tax_amount' => 100,
            'total' => 1100,
        ]));

        $this->putJson("/api/v1/orders/{$order->id}", ['discount_amount' => '100.00'])->assertOk();

        $order->refresh();

        // No active rule, so re-pricing this sale drops the tax that was never
        // configured in the first place.
        $this->assertEquals(0, (float) $order->tax_amount);
        $this->assertEquals(900, (float) $order->total);
    }
}
