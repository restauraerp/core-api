<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseOrderSearchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $kitchen;

    private Supplier $wholesale;

    private Supplier $dairy;

    private InventoryItem $rice;

    private InventoryItem $milk;

    private InventoryItem $chicken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'po-search-test']);

        app(TenantContext::class)->runFor($this->tenant, function () {
            $this->kitchen = Location::create(['name' => 'Downtown Kitchen']);
            $this->wholesale = Supplier::create(['name' => 'National Food Wholesale']);
            $this->dairy = Supplier::create(['name' => 'Farm Fresh Dairy']);

            $this->rice = InventoryItem::create([
                'title' => 'Chinigura Aromatic Rice',
                'sku' => 'RICE-CHINI-01',
                'unit' => 'kg',
            ]);
            $this->milk = InventoryItem::create([
                'title' => 'Whole Cow Milk',
                'sku' => 'MILK-COW-01',
                'unit' => 'liter',
            ]);
            $this->chicken = InventoryItem::create([
                'title' => 'Broiler Chicken Whole',
                'sku' => 'MEAT-CHICK-01',
                'unit' => 'kg',
            ]);
        });

        Sanctum::actingAs(User::factory()->forTenant($this->tenant)->create());
    }

    private function createPurchaseOrder(Supplier $supplier, array $items, string $status = 'received', ?string $notes = null): int
    {
        $response = $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier->id,
            'location_id' => $this->kitchen->id,
            'status' => $status,
            'notes' => $notes,
            'items' => array_map(fn ($item) => [
                'inventory_item_id' => $item['item']->id,
                'quantity' => $item['quantity'],
                'price' => $item['price'],
            ], $items),
        ])->assertCreated();

        return $response->json('id');
    }

    public function test_can_search_purchase_orders_by_inventory_item_title(): void
    {
        $po1 = $this->createPurchaseOrder($this->wholesale, [
            ['item' => $this->rice, 'quantity' => 100, 'price' => 110],
        ]);
        $po2 = $this->createPurchaseOrder($this->dairy, [
            ['item' => $this->milk, 'quantity' => 50, 'price' => 85],
        ]);

        // Search for "Aromatic Rice" should return PO1 and NOT PO2
        $response = $this->getJson('/api/v1/purchase-orders?search=Aromatic+Rice')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($po1, $ids);
        $this->assertNotContains($po2, $ids);
    }

    public function test_can_search_purchase_orders_by_inventory_item_sku(): void
    {
        $po1 = $this->createPurchaseOrder($this->wholesale, [
            ['item' => $this->rice, 'quantity' => 50, 'price' => 110],
        ]);
        $po2 = $this->createPurchaseOrder($this->dairy, [
            ['item' => $this->milk, 'quantity' => 30, 'price' => 85],
        ]);

        $response = $this->getJson('/api/v1/purchase-orders?search=MILK-COW')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($po2, $ids);
        $this->assertNotContains($po1, $ids);
    }

    public function test_can_search_purchase_orders_by_supplier_name(): void
    {
        $po1 = $this->createPurchaseOrder($this->wholesale, [
            ['item' => $this->rice, 'quantity' => 50, 'price' => 110],
        ]);
        $po2 = $this->createPurchaseOrder($this->dairy, [
            ['item' => $this->milk, 'quantity' => 30, 'price' => 85],
        ]);

        $response = $this->getJson('/api/v1/purchase-orders?search=Farm+Fresh')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($po2, $ids);
        $this->assertNotContains($po1, $ids);
    }

    public function test_can_search_purchase_orders_by_id_or_number(): void
    {
        $po1 = $this->createPurchaseOrder($this->wholesale, [
            ['item' => $this->rice, 'quantity' => 50, 'price' => 110],
        ]);
        $po2 = $this->createPurchaseOrder($this->dairy, [
            ['item' => $this->milk, 'quantity' => 30, 'price' => 85],
        ]);

        // Search with bare ID
        $response = $this->getJson("/api/v1/purchase-orders?search={$po1}")
            ->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($po1, $ids);
        $this->assertNotContains($po2, $ids);

        // Search with prefixed hash "#123"
        $responseHash = $this->getJson("/api/v1/purchase-orders?search=%23{$po2}")
            ->assertOk();
        $hashIds = collect($responseHash->json('data'))->pluck('id')->all();
        $this->assertContains($po2, $hashIds);
        $this->assertNotContains($po1, $hashIds);
    }

    public function test_can_search_purchase_orders_by_notes(): void
    {
        $po1 = $this->createPurchaseOrder($this->wholesale, [
            ['item' => $this->rice, 'quantity' => 50, 'price' => 110],
        ], 'received', 'Emergency replenishment for weekend catering event');

        $po2 = $this->createPurchaseOrder($this->dairy, [
            ['item' => $this->milk, 'quantity' => 30, 'price' => 85],
        ], 'received', 'Regular weekly dairy delivery');

        $response = $this->getJson('/api/v1/purchase-orders?search=catering+event')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($po1, $ids);
        $this->assertNotContains($po2, $ids);
    }

    public function test_unmatched_search_returns_empty_results(): void
    {
        $this->createPurchaseOrder($this->wholesale, [
            ['item' => $this->rice, 'quantity' => 50, 'price' => 110],
        ]);

        $response = $this->getJson('/api/v1/purchase-orders?search=NonExistentItemNameXYZ')
            ->assertOk();

        $this->assertEmpty($response->json('data'));
    }

    public function test_search_works_together_with_status_filter(): void
    {
        // Two POs with rice: one received, one pending
        $poReceived = $this->createPurchaseOrder($this->wholesale, [
            ['item' => $this->rice, 'quantity' => 50, 'price' => 110],
        ], 'received');

        $poPending = $this->createPurchaseOrder($this->wholesale, [
            ['item' => $this->rice, 'quantity' => 20, 'price' => 110],
        ], 'pending');

        $response = $this->getJson('/api/v1/purchase-orders?search=Chinigura&status=received')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($poReceived, $ids);
        $this->assertNotContains($poPending, $ids);
    }
}
