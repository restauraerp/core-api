<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Purchase orders are the only way stock legitimately appears in a restaurant,
 * so these tests are mostly about one question: after this request, is the
 * inventory level right?
 */
class PurchaseOrderApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    private Supplier $supplier;

    private InventoryItem $rice;

    private InventoryItem $oil;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'po-test']);

        app(TenantContext::class)->runFor($this->tenant, function () {
            $this->location = Location::create(['name' => 'Main Kitchen']);
            $this->supplier = Supplier::create(['name' => 'Dhaka Wholesale']);
            $this->rice = InventoryItem::create(['title' => 'Basmati Rice', 'description' => 'Long grain, 25kg sack', 'unit' => 'kg']);
            $this->oil = InventoryItem::create(['title' => 'Soyabean Oil', 'description' => 'Refined, 5L can', 'unit' => 'liter']);
        });

        Sanctum::actingAs(User::factory()->forTenant($this->tenant)->create());
    }

    /**
     * @param  array<int, array{inventory_item_id: int, quantity: float|int, price: float|int}>|null  $items
     * @return array<string, mixed>
     */
    private function payload(?array $items = null, array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->id,
            'location_id' => $this->location->id,
            'items' => $items ?? [
                ['inventory_item_id' => $this->rice->id, 'quantity' => 50, 'price' => 120],
                ['inventory_item_id' => $this->oil->id, 'quantity' => 20, 'price' => 170],
            ],
        ], $overrides);
    }

    /**
     * A stand-in for a photographed receipt. Built from a mime type rather than
     * UploadedFile::fake()->image(), which needs the GD extension - absent from
     * the API container, so the image helper cannot run here.
     */
    private function fakeReceipt(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'image/jpeg');
    }

    private function stockOf(InventoryItem $item): float
    {
        return (float) $item->fresh()->locations()
            ->where('locations.id', $this->location->id)
            ->first()?->pivot->quantity;
    }

    public function test_creating_an_order_stores_its_lines_and_totals_them(): void
    {
        $response = $this->postJson('/api/v1/purchase-orders', $this->payload());

        $response->assertCreated();
        $this->assertCount(2, $response->json('items'));
        // 50 x 120 + 20 x 170 = 9400
        $this->assertEquals(9400, $response->json('total_amount'));
    }

    public function test_creating_an_order_adds_its_quantities_to_inventory(): void
    {
        $this->postJson('/api/v1/purchase-orders', $this->payload())->assertCreated();

        $this->assertSame(50.0, $this->stockOf($this->rice));
        $this->assertSame(20.0, $this->stockOf($this->oil));
        $this->assertSame(50.0, (float) $this->rice->fresh()->current_stock);
    }

    public function test_a_delivery_adds_to_the_stock_already_on_hand(): void
    {
        $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['inventory_item_id' => $this->rice->id, 'quantity' => 30, 'price' => 120],
        ]))->assertCreated();

        $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['inventory_item_id' => $this->rice->id, 'quantity' => 25, 'price' => 125],
        ]))->assertCreated();

        $this->assertSame(55.0, $this->stockOf($this->rice));
    }

    public function test_two_lines_naming_the_same_item_are_summed(): void
    {
        $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['inventory_item_id' => $this->rice->id, 'quantity' => 10, 'price' => 120],
            ['inventory_item_id' => $this->rice->id, 'quantity' => 15, 'price' => 130],
        ]))->assertCreated();

        $this->assertSame(25.0, $this->stockOf($this->rice));
    }

    public function test_editing_the_quantities_restates_inventory(): void
    {
        $id = $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['inventory_item_id' => $this->rice->id, 'quantity' => 50, 'price' => 120],
        ]))->json('id');

        $this->putJson("/api/v1/purchase-orders/{$id}", $this->payload([
            ['inventory_item_id' => $this->rice->id, 'quantity' => 30, 'price' => 120],
        ]))->assertOk();

        $this->assertSame(30.0, $this->stockOf($this->rice));
        $this->assertEquals(3600, PurchaseOrder::find($id)->total_amount);
    }

    public function test_moving_an_order_to_another_outlet_moves_the_stock(): void
    {
        $other = app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => Location::create(['name' => 'Branch Two']),
        );

        $id = $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['inventory_item_id' => $this->rice->id, 'quantity' => 40, 'price' => 120],
        ]))->json('id');

        $this->putJson("/api/v1/purchase-orders/{$id}", $this->payload(
            [['inventory_item_id' => $this->rice->id, 'quantity' => 40, 'price' => 120]],
            ['location_id' => $other->id],
        ))->assertOk();

        $this->assertSame(0.0, $this->stockOf($this->rice));
        $this->assertSame(40.0, (float) $this->rice->fresh()->locations()
            ->where('locations.id', $other->id)->first()->pivot->quantity);
    }

    public function test_cancelling_an_order_takes_the_stock_back_out(): void
    {
        $id = $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['inventory_item_id' => $this->rice->id, 'quantity' => 40, 'price' => 120],
        ]))->json('id');

        $this->putJson("/api/v1/purchase-orders/{$id}", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(0.0, $this->stockOf($this->rice));
        $this->assertFalse(PurchaseOrder::find($id)->stock_applied);
    }

    public function test_reopening_a_cancelled_order_puts_the_stock_back(): void
    {
        $id = $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['inventory_item_id' => $this->rice->id, 'quantity' => 40, 'price' => 120],
        ]))->json('id');

        $this->putJson("/api/v1/purchase-orders/{$id}", ['status' => 'cancelled'])->assertOk();
        $this->putJson("/api/v1/purchase-orders/{$id}", ['status' => 'received'])->assertOk();

        $this->assertSame(40.0, $this->stockOf($this->rice));
    }

    public function test_an_order_created_as_cancelled_never_touches_inventory(): void
    {
        $this->postJson('/api/v1/purchase-orders', $this->payload(
            [['inventory_item_id' => $this->rice->id, 'quantity' => 40, 'price' => 120]],
            ['status' => 'cancelled'],
        ))->assertCreated();

        $this->assertSame(0.0, $this->stockOf($this->rice));
    }

    public function test_deleting_an_order_takes_the_stock_back_out(): void
    {
        $id = $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['inventory_item_id' => $this->rice->id, 'quantity' => 40, 'price' => 120],
        ]))->json('id');

        $this->deleteJson("/api/v1/purchase-orders/{$id}")->assertNoContent();

        $this->assertSame(0.0, $this->stockOf($this->rice));
    }

    public function test_a_delivery_sets_the_items_cost_per_unit(): void
    {
        $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['inventory_item_id' => $this->rice->id, 'quantity' => 10, 'price' => 135],
        ]))->assertCreated();

        $this->assertEquals(135, $this->rice->fresh()->cost_per_unit);
    }

    public function test_the_newest_delivery_is_the_one_that_prices_an_item(): void
    {
        $older = $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['inventory_item_id' => $this->rice->id, 'quantity' => 10, 'price' => 100],
        ]))->json('id');

        $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['inventory_item_id' => $this->rice->id, 'quantity' => 10, 'price' => 150],
        ]))->assertCreated();

        // Correcting last month's invoice must not overwrite this week's price.
        $this->putJson("/api/v1/purchase-orders/{$older}", $this->payload([
            ['inventory_item_id' => $this->rice->id, 'quantity' => 10, 'price' => 90],
        ]))->assertOk();

        $this->assertEquals(150, $this->rice->fresh()->cost_per_unit);
    }

    public function test_deleting_the_newest_delivery_falls_back_to_the_previous_price(): void
    {
        $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['inventory_item_id' => $this->rice->id, 'quantity' => 10, 'price' => 100],
        ]))->assertCreated();

        $newest = $this->postJson('/api/v1/purchase-orders', $this->payload([
            ['inventory_item_id' => $this->rice->id, 'quantity' => 10, 'price' => 150],
        ]))->json('id');

        $this->deleteJson("/api/v1/purchase-orders/{$newest}")->assertNoContent();

        $this->assertEquals(100, $this->rice->fresh()->cost_per_unit);
    }

    public function test_an_order_without_lines_is_refused(): void
    {
        $this->postJson('/api/v1/purchase-orders', $this->payload([]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_the_client_cannot_dictate_the_total(): void
    {
        $response = $this->postJson('/api/v1/purchase-orders', $this->payload(
            [['inventory_item_id' => $this->rice->id, 'quantity' => 10, 'price' => 100]],
            ['total_amount' => 5],
        ));

        $this->assertEquals(1000, $response->json('total_amount'));
    }

    public function test_receipt_images_are_stored_against_the_order(): void
    {
        Storage::fake('public');

        $response = $this->post('/api/v1/purchase-orders', $this->payload(null, [
            'receipts' => [$this->fakeReceipt('invoice.jpg'), $this->fakeReceipt('delivery-note.jpg')],
        ]));

        $response->assertCreated();
        $this->assertCount(2, $response->json('receipts'));

        foreach ($response->json('receipts') as $receipt) {
            $this->assertSame('receipt', $receipt['type']);
            Storage::disk('public')->assertExists($receipt['url']);
        }
    }

    public function test_receipts_can_be_removed_on_update(): void
    {
        Storage::fake('public');

        $created = $this->post('/api/v1/purchase-orders', $this->payload(null, [
            'receipts' => [$this->fakeReceipt('invoice.jpg')],
        ]))->json();

        $this->putJson("/api/v1/purchase-orders/{$created['id']}", [
            'remove_receipt_ids' => [$created['receipts'][0]['id']],
        ])->assertOk();

        $this->assertCount(0, PurchaseOrder::find($created['id'])->receipts);
    }

    public function test_another_tenants_supplier_is_refused(): void
    {
        $stranger = Tenant::factory()->create(['slug' => 'other-po-tenant']);
        $theirSupplier = app(TenantContext::class)->runFor(
            $stranger,
            fn () => Supplier::create(['name' => 'Someone Elses Supplier']),
        );

        $this->postJson('/api/v1/purchase-orders', $this->payload(null, [
            'supplier_id' => $theirSupplier->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('supplier_id');
    }

    public function test_index_returns_orders_with_their_lines_and_supplier(): void
    {
        $this->postJson('/api/v1/purchase-orders', $this->payload())->assertCreated();

        $response = $this->getJson('/api/v1/purchase-orders');

        $response->assertOk();
        $this->assertSame('Dhaka Wholesale', $response->json('data.0.supplier.name'));
        $this->assertCount(2, $response->json('data.0.items'));
        $this->assertSame('Basmati Rice', $response->json('data.0.items.0.inventory_item.title'));
    }

    public function test_index_requires_auth(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/purchase-orders')->assertStatus(401);
    }
}
