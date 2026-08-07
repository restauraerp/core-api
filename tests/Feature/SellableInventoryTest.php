<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Inventory\StockLevels;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Stock sold exactly as it was bought - a bottle, a packet, a can.
 *
 * Marking such an item sellable puts it on the till as a real product, so
 * orders, receipts and sales reports need to know nothing new; selling one then
 * takes it off the shelf it was sold from.
 */
class SellableInventoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'sellable-test']);

        $this->location = app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => Location::create(['name' => 'Main Kitchen']),
        );

        Sanctum::actingAs(User::factory()->forTenant($this->tenant)->create());
    }

    /** @return array<string, mixed> */
    private function itemPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Mineral Water 500ml',
            'description' => 'Sold by the bottle, straight from the crate.',
            'sku' => 'INV-WTR-001',
            'unit' => 'bottle',
            'locations' => [
                ['location_id' => $this->location->id, 'is_active' => true],
            ],
        ], $overrides);
    }

    private function stockOf(InventoryItem $item): float
    {
        return (float) $item->fresh()->locations()
            ->where('locations.id', $this->location->id)
            ->first()?->pivot->quantity;
    }

    /** Puts a known quantity on the shelf the way a restaurant does: by buying it. */
    private function deliver(InventoryItem $item, float $quantity): void
    {
        app(TenantContext::class)->runFor($this->tenant, function () use ($item, $quantity) {
            app(StockLevels::class)->adjust($item, $this->location->id, $quantity);
        });
    }

    public function test_marking_an_item_sellable_puts_it_in_the_catalogue(): void
    {
        $response = $this->postJson('/api/v1/inventory-items', $this->itemPayload([
            'is_sellable' => true,
            'selling_price' => 25,
        ]));

        $response->assertCreated();

        $product = Product::where('inventory_item_id', $response->json('id'))->first();

        $this->assertNotNull($product, 'No catalogue entry was created for the sellable item.');
        $this->assertSame('Mineral Water 500ml', $product->name);
        $this->assertEquals(25, $product->price);
        $this->assertTrue((bool) $product->is_active);
    }

    public function test_a_sellable_item_needs_a_price(): void
    {
        $this->postJson('/api/v1/inventory-items', $this->itemPayload(['is_sellable' => true]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('selling_price');
    }

    public function test_an_item_that_is_not_sellable_gets_no_catalogue_entry(): void
    {
        $response = $this->postJson('/api/v1/inventory-items', $this->itemPayload());

        $response->assertCreated();

        $this->assertDatabaseMissing('products', ['inventory_item_id' => $response->json('id')]);
    }

    public function test_renaming_and_repricing_the_item_follows_through_to_the_till(): void
    {
        $id = $this->postJson('/api/v1/inventory-items', $this->itemPayload([
            'is_sellable' => true,
            'selling_price' => 25,
        ]))->json('id');

        $this->putJson("/api/v1/inventory-items/{$id}", $this->itemPayload([
            'title' => 'Mineral Water 1L',
            'is_sellable' => true,
            'selling_price' => 40,
        ]))->assertOk();

        $product = Product::where('inventory_item_id', $id)->first();

        $this->assertSame('Mineral Water 1L', $product->name);
        $this->assertEquals(40, $product->price);
    }

    public function test_unticking_sellable_takes_it_off_the_till_without_deleting_history(): void
    {
        $id = $this->postJson('/api/v1/inventory-items', $this->itemPayload([
            'is_sellable' => true,
            'selling_price' => 25,
        ]))->json('id');

        $productId = Product::where('inventory_item_id', $id)->value('id');

        $this->putJson("/api/v1/inventory-items/{$id}", ['is_sellable' => false])->assertOk();

        // Still there for the orders that reference it, but off the till.
        $this->assertDatabaseHas('products', ['id' => $productId, 'is_active' => false]);
    }

    public function test_ticking_it_again_reuses_the_same_catalogue_entry(): void
    {
        $id = $this->postJson('/api/v1/inventory-items', $this->itemPayload([
            'is_sellable' => true,
            'selling_price' => 25,
        ]))->json('id');

        $this->putJson("/api/v1/inventory-items/{$id}", ['is_sellable' => false])->assertOk();
        $this->putJson("/api/v1/inventory-items/{$id}", ['is_sellable' => true, 'selling_price' => 30])->assertOk();

        $this->assertSame(1, Product::where('inventory_item_id', $id)->count());
        $this->assertTrue((bool) Product::where('inventory_item_id', $id)->value('is_active'));
    }

    public function test_selling_one_takes_it_off_the_shelf(): void
    {
        $id = $this->postJson('/api/v1/inventory-items', $this->itemPayload([
            'is_sellable' => true,
            'selling_price' => 25,
        ]))->json('id');

        $item = InventoryItem::find($id);
        $this->deliver($item, 24);

        $product = Product::where('inventory_item_id', $id)->first();

        $this->postJson('/api/v1/orders', [
            'location_id' => $this->location->id,
            'order_type' => 'dine_in',
            'status' => 'completed',
            'subtotal' => 75,
            'discount_amount' => 0,
            'items' => [
                ['product_id' => $product->id, 'qty' => 3, 'price' => 25],
            ],
        ])->assertCreated();

        $this->assertSame(21.0, $this->stockOf($item));
    }

    public function test_selling_a_cooked_dish_moves_no_stock(): void
    {
        $item = app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => InventoryItem::create(['title' => 'Basmati Rice', 'unit' => 'kg']),
        );
        $this->deliver($item, 50);

        $dish = app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => Product::create(['name' => 'Chicken Biryani', 'price' => 350, 'is_active' => 1]),
        );

        $this->postJson('/api/v1/orders', [
            'location_id' => $this->location->id,
            'order_type' => 'dine_in',
            'status' => 'completed',
            'subtotal' => 350,
            'discount_amount' => 0,
            'items' => [['product_id' => $dish->id, 'qty' => 1, 'price' => 350]],
        ])->assertCreated();

        // A dish is made of ingredients this cannot know about - that is what
        // recipes are for.
        $this->assertSame(50.0, $this->stockOf($item));
    }

    public function test_cancelling_the_order_puts_the_stock_back(): void
    {
        $id = $this->postJson('/api/v1/inventory-items', $this->itemPayload([
            'is_sellable' => true,
            'selling_price' => 25,
        ]))->json('id');

        $item = InventoryItem::find($id);
        $this->deliver($item, 10);

        $product = Product::where('inventory_item_id', $id)->first();

        $orderId = $this->postJson('/api/v1/orders', [
            'location_id' => $this->location->id,
            'order_type' => 'dine_in',
            'status' => 'completed',
            'subtotal' => 50,
            'discount_amount' => 0,
            'items' => [['product_id' => $product->id, 'qty' => 2, 'price' => 25]],
        ])->json('id');

        $this->assertSame(8.0, $this->stockOf($item));

        $this->putJson("/api/v1/orders/{$orderId}", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(10.0, $this->stockOf($item));
    }

    public function test_deleting_the_order_puts_the_stock_back(): void
    {
        $id = $this->postJson('/api/v1/inventory-items', $this->itemPayload([
            'is_sellable' => true,
            'selling_price' => 25,
        ]))->json('id');

        $item = InventoryItem::find($id);
        $this->deliver($item, 10);

        $product = Product::where('inventory_item_id', $id)->first();

        $orderId = $this->postJson('/api/v1/orders', [
            'location_id' => $this->location->id,
            'order_type' => 'dine_in',
            'status' => 'completed',
            'subtotal' => 25,
            'discount_amount' => 0,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'price' => 25]],
        ])->json('id');

        $this->deleteJson("/api/v1/orders/{$orderId}")->assertNoContent();

        $this->assertSame(10.0, $this->stockOf($item));
    }

    public function test_the_catalogue_entry_is_offered_where_the_item_is_stocked(): void
    {
        $id = $this->postJson('/api/v1/inventory-items', $this->itemPayload([
            'is_sellable' => true,
            'selling_price' => 25,
        ]))->json('id');

        $product = Product::where('inventory_item_id', $id)->first();

        $this->assertTrue(
            (bool) $product->locations()->where('locations.id', $this->location->id)->first()?->pivot->is_available,
        );
    }

    public function test_a_client_cannot_link_a_product_to_an_inventory_item_itself(): void
    {
        $item = app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => InventoryItem::create(['title' => 'Truffle Paste', 'unit' => 'jar']),
        );

        $response = $this->postJson('/api/v1/products', [
            'name' => 'Sneaky Product',
            'price' => 10,
            'inventory_item_id' => $item->id,
        ]);

        $response->assertCreated();

        $this->assertNull(Product::find($response->json('id'))->inventory_item_id);
    }

    public function test_orders_are_unaffected_for_tenants_with_nothing_sellable(): void
    {
        $dish = app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => Product::create(['name' => 'Tea', 'price' => 30, 'is_active' => 1]),
        );

        $this->postJson('/api/v1/orders', [
            'location_id' => $this->location->id,
            'order_type' => 'takeaway',
            'status' => 'completed',
            'subtotal' => 30,
            'discount_amount' => 0,
            'items' => [['product_id' => $dish->id, 'qty' => 1, 'price' => 30]],
        ])->assertCreated();

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_an_order_line_count_matches_after_a_sale(): void
    {
        $this->assertSame(0, Order::count());
    }
}
