<?php

namespace Tests\Feature;

use App\Models\ConsumptionLog;
use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reporting what the kitchen used, and correcting it afterwards.
 *
 * These figures move stock off the shelf, which is what makes both halves
 * matter: reporting eight things one form at a time is how a restaurant stops
 * bothering, and a quantity that can be changed with no trace is one nobody can
 * reconcile against.
 */
class ConsumptionLogTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $outlet;

    private InventoryItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Consumption Restaurant',
            'slug' => 'consumption-restaurant',
            'plan' => 'enterprise',
            'status' => 'active',
        ]);

        [$this->outlet, $this->item] = $this->inTenant(function () {
            $outlet = Location::query()->firstOrFail();

            $item = InventoryItem::create([
                'title' => 'Basmati Rice',
                'description' => 'Basmati Rice',
                'unit' => 'kg',
                'cost_per_unit' => 120,
                'min_stock_level' => 5,
            ]);

            DB::table('inventory_item_location')->insert([
                'tenant_id' => $this->tenant->getKey(),
                'inventory_item_id' => $item->getKey(),
                'location_id' => $outlet->getKey(),
                'quantity' => 100,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [$outlet, $item];
        });
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function actAs(bool $admin): User
    {
        $user = $this->inTenant(function () use ($admin) {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);

            if ($admin) {
                $user->assignRole(RoleDefinitions::RESTAURANT_ADMIN);
            }

            return $user;
        });

        Sanctum::actingAs($user);

        return $user;
    }

    private function headers(): array
    {
        return ['X-Tenant-ID' => $this->tenant->slug];
    }

    private function stock(): float
    {
        return (float) DB::table('inventory_item_location')
            ->where('inventory_item_id', $this->item->getKey())
            ->where('location_id', $this->outlet->getKey())
            ->value('quantity');
    }

    /** @param array<string, mixed> $overrides */
    private function entry(array $overrides = []): array
    {
        return array_merge([
            'inventory_item_id' => $this->item->getKey(),
            'location_id' => $this->outlet->getKey(),
            'quantity' => 2,
            'consumed_at' => '2026-08-19',
        ], $overrides);
    }

    #[Test]
    public function several_items_can_be_reported_at_once(): void
    {
        $this->actAs(admin: false);

        $this->postJson('/api/v1/consumption-logs/batch', [
            'entries' => [
                $this->entry(['quantity' => 2, 'reason' => 'Staff meal']),
                $this->entry(['quantity' => 3, 'reason' => 'Spillage']),
            ],
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('created', 2);

        $this->assertSame(95.0, $this->stock());
    }

    /**
     * Eight rows that half-saved would leave stock wrong in a way nobody could
     * see, which is worse than a refusal.
     */
    #[Test]
    public function a_batch_with_one_bad_row_saves_nothing(): void
    {
        $this->actAs(admin: false);

        $this->postJson('/api/v1/consumption-logs/batch', [
            'entries' => [
                $this->entry(['quantity' => 2]),
                $this->entry(['quantity' => 0]),
            ],
        ], $this->headers())->assertStatus(422);

        $this->assertSame(100.0, $this->stock());
        $this->assertDatabaseCount('consumption_logs', 0);
    }

    #[Test]
    public function only_an_admin_may_correct_a_log(): void
    {
        $this->actAs(admin: false);

        $id = $this->postJson('/api/v1/consumption-logs', $this->entry(), $this->headers())
            ->assertCreated()->json('id');

        $this->putJson("/api/v1/consumption-logs/{$id}", ['quantity' => 5], $this->headers())
            ->assertForbidden();

        $this->assertEquals(2, ConsumptionLog::findOrFail($id)->quantity);
    }

    #[Test]
    public function correcting_the_quantity_moves_only_the_difference(): void
    {
        $this->actAs(admin: false);

        $id = $this->postJson('/api/v1/consumption-logs', $this->entry(['quantity' => 2]), $this->headers())
            ->assertCreated()->json('id');

        $this->assertSame(98.0, $this->stock());

        $this->actAs(admin: true);

        $this->putJson("/api/v1/consumption-logs/{$id}", ['quantity' => 5], $this->headers())
            ->assertOk();

        // Three more consumed, not five more.
        $this->assertSame(95.0, $this->stock());
    }

    #[Test]
    public function a_correction_records_who_made_it_and_what_it_said_before(): void
    {
        $this->actAs(admin: false);

        $id = $this->postJson('/api/v1/consumption-logs', $this->entry(['quantity' => 2]), $this->headers())
            ->assertCreated()->json('id');

        $admin = $this->actAs(admin: true);

        $this->putJson("/api/v1/consumption-logs/{$id}", ['quantity' => 5], $this->headers())->assertOk();

        $log = ConsumptionLog::findOrFail($id);

        $this->assertSame($admin->getKey(), $log->edited_by);
        $this->assertNotNull($log->edited_at);
        $this->assertEquals(2, $log->original_quantity);

        // A second correction does not overwrite what it originally claimed.
        $this->putJson("/api/v1/consumption-logs/{$id}", ['quantity' => 7], $this->headers())->assertOk();

        $this->assertEquals(2, $log->fresh()->original_quantity);
    }

    /**
     * A log moved between outlets has to put the stock back where it came from
     * before taking it from the new one, or the first outlet stays short.
     */
    #[Test]
    public function moving_a_log_to_another_outlet_restores_the_first(): void
    {
        $this->actAs(admin: false);

        $id = $this->postJson('/api/v1/consumption-logs', $this->entry(['quantity' => 4]), $this->headers())
            ->assertCreated()->json('id');

        $this->assertSame(96.0, $this->stock());

        $second = $this->inTenant(fn () => Location::create(['name' => 'Second', 'type' => 'branch', 'is_active' => true]));

        $this->actAs(admin: true);

        $this->putJson("/api/v1/consumption-logs/{$id}", ['location_id' => $second->getKey()], $this->headers())
            ->assertOk();

        // The original outlet is whole again.
        $this->assertSame(100.0, $this->stock());

        $moved = (float) DB::table('inventory_item_location')
            ->where('inventory_item_id', $this->item->getKey())
            ->where('location_id', $second->getKey())
            ->value('quantity');

        $this->assertSame(-4.0, $moved);
    }
}
