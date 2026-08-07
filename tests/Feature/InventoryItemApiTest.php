<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Inventory items describe *what* a restaurant stocks. How much of it is on
 * hand is not editable here: stock arrives on a purchase order, so a level
 * typed into this screen would be a number with no document behind it.
 */
class InventoryItemApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'inventory-test']);

        $this->location = app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => Location::create(['name' => 'Main Kitchen']),
        );

        Sanctum::actingAs(User::factory()->forTenant($this->tenant)->create());
    }

    private function stockOf(InventoryItem $item): float
    {
        return (float) $item->fresh()->locations()
            ->where('locations.id', $this->location->id)
            ->first()?->pivot->quantity;
    }

    public function test_index_requires_auth(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/inventory-items')->assertStatus(401);
    }

    public function test_index_returns_200_for_an_authenticated_user(): void
    {
        $this->getJson('/api/v1/inventory-items')->assertOk();
    }

    public function test_a_new_item_starts_with_no_stock_whatever_the_request_says(): void
    {
        $response = $this->postJson('/api/v1/inventory-items', [
            'title' => 'Basmati Rice',
            'unit' => 'kg',
            'current_stock' => 500,
            'locations' => [
                ['location_id' => $this->location->id, 'quantity' => 500, 'is_active' => true],
            ],
        ]);

        $response->assertCreated();

        $item = InventoryItem::find($response->json('id'));

        $this->assertSame(0.0, (float) $item->current_stock);
        $this->assertSame(0.0, $this->stockOf($item));
    }

    public function test_editing_an_item_leaves_the_stock_it_holds_alone(): void
    {
        $item = app(TenantContext::class)->runFor($this->tenant, function () {
            $item = InventoryItem::create(['title' => 'Chicken Breast', 'unit' => 'kg']);
            $item->locations()->attach($this->location->id, ['quantity' => 42, 'is_active' => true]);

            return $item;
        });

        $this->putJson("/api/v1/inventory-items/{$item->id}", [
            'title' => 'Chicken Breast (Skinless)',
            // Both of these are ignored - stock is not editable here.
            'current_stock' => 9999,
            'locations' => [
                ['location_id' => $this->location->id, 'quantity' => 9999, 'is_active' => true],
            ],
        ])->assertOk();

        $this->assertSame('Chicken Breast (Skinless)', $item->fresh()->title);
        $this->assertSame(42.0, $this->stockOf($item));
        $this->assertSame(42.0, (float) $item->fresh()->current_stock);
    }

    public function test_the_cost_per_unit_cannot_be_typed_in(): void
    {
        $item = app(TenantContext::class)->runFor(
            $this->tenant,
            // forceFill, because cost_per_unit is not fillable: it is written
            // only by a delivery. Standing in for one here.
            fn () => tap(
                InventoryItem::create(['title' => 'Butter', 'unit' => 'kg']),
                fn (InventoryItem $item) => $item->forceFill(['cost_per_unit' => 600])->save(),
            ),
        );

        $this->putJson("/api/v1/inventory-items/{$item->id}", [
            'title' => 'Butter Block',
            'cost_per_unit' => 1,
        ])->assertOk();

        $this->assertEquals(600, $item->fresh()->cost_per_unit);
    }

    public function test_switching_an_outlet_off_keeps_what_it_holds(): void
    {
        $item = app(TenantContext::class)->runFor($this->tenant, function () {
            $item = InventoryItem::create(['title' => 'Soyabean Oil', 'unit' => 'liter']);
            $item->locations()->attach($this->location->id, ['quantity' => 18, 'is_active' => true]);

            return $item;
        });

        $this->putJson("/api/v1/inventory-items/{$item->id}", [
            'locations' => [
                ['location_id' => $this->location->id, 'is_active' => false],
            ],
        ])->assertOk();

        $this->assertSame(18.0, $this->stockOf($item));
    }
}
