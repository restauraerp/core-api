<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Deleting an outlet must never be how a restaurant loses its books.
 *
 * `orders.location_id` is declared `cascadeOnDelete` and locations are not soft
 * deleted, so removing one used to hard-delete every order rung through it,
 * every line on those orders and every payment against them - behind a
 * confirm() that mentioned none of it.
 */
class LocationDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Provisioned rather than factory-made, because a real tenant comes
        // with its head office already created - which is the "only outlet"
        // these tests are about.
        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Outlet Restaurant',
            'slug' => 'outlet-restaurant',
            'plan' => 'enterprise',
            'status' => 'active',
        ]);

        Sanctum::actingAs(app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => User::factory()->create(['tenant_id' => $this->tenant->getKey()]),
        ));
    }

    /**
     * Written inside the tenant's context: BelongsToTenant stamps tenant_id
     * from there, and a bare Model::create() in a test has no context to read.
     */
    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function location(string $name): Location
    {
        return $this->inTenant(fn () => Location::create([
            'name' => $name,
            'type' => 'branch',
            'is_active' => true,
        ]));
    }

    /** @param array<string, mixed> $attributes */
    private function order(Location $location, array $attributes = []): Order
    {
        return $this->inTenant(fn () => Order::create(array_merge([
            'location_id' => $location->getKey(),
            'order_type' => 'dine_in',
            'status' => 'served',
            'subtotal' => 100,
            'total' => 100,
        ], $attributes)));
    }

    private function headOffice(): Location
    {
        return $this->inTenant(fn () => Location::query()->firstOrFail());
    }

    #[Test]
    public function the_only_outlet_cannot_be_deleted(): void
    {
        $only = $this->headOffice();

        $this->assertSame(1, $this->tenant->locations()->count());

        $this->deleteJson("/api/v1/locations/{$only->getKey()}")
            ->assertStatus(422)
            ->assertJsonPath('error', 'last_location');

        $this->assertDatabaseHas('locations', ['id' => $only->getKey()]);
    }

    #[Test]
    public function an_outlet_that_has_traded_cannot_be_deleted(): void
    {
        $branch = $this->location('Banani');
        $order = $this->order($branch, ['payment_status' => 'paid']);

        $this->deleteJson("/api/v1/locations/{$branch->getKey()}")
            ->assertStatus(422)
            ->assertJsonPath('error', 'location_has_orders')
            ->assertJsonPath('orders', 1);

        // The point of the whole test: the order is still there.
        $this->assertDatabaseHas('orders', ['id' => $order->getKey()]);
        $this->assertDatabaseHas('locations', ['id' => $branch->getKey()]);
    }

    /**
     * A trashed order is still a row, and the database cascade does not care
     * that the application considers it deleted.
     */
    #[Test]
    public function a_trashed_order_still_protects_its_outlet(): void
    {
        $branch = $this->location('Banani');
        $this->order($branch)->delete();

        $this->deleteJson("/api/v1/locations/{$branch->getKey()}")
            ->assertStatus(422)
            ->assertJsonPath('error', 'location_has_orders');
    }

    /**
     * The escape hatch has to work, or this is just an outlet nobody can ever
     * remove: a second outlet opened by mistake, never traded, still goes.
     */
    #[Test]
    public function an_outlet_that_never_traded_can_still_be_deleted(): void
    {
        $mistake = $this->location('Opened By Mistake');

        $this->deleteJson("/api/v1/locations/{$mistake->getKey()}")
            ->assertNoContent();

        $this->assertDatabaseMissing('locations', ['id' => $mistake->getKey()]);
    }

    /**
     * Retiring a branch is switching it off, which is what the refusal tells
     * the user to do - so it has to be possible.
     */
    #[Test]
    public function an_outlet_that_has_traded_can_be_switched_off(): void
    {
        $branch = $this->location('Banani');
        $this->order($branch);

        $this->putJson("/api/v1/locations/{$branch->getKey()}", [
            'name' => 'Banani',
            'type' => 'branch',
            'is_active' => false,
        ])->assertOk();

        $this->assertFalse((bool) $branch->fresh()->is_active);
        $this->assertDatabaseCount('orders', 1);
    }
}
