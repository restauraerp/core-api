<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\Order;
use App\Models\StorageUnit;
use App\Models\Supplier;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Proof that a create actually saves what was submitted.
 *
 * Twenty controllers shipped with `$request->validate([])` - an empty rule set,
 * which returns an empty array. Every field a user typed was discarded,
 * `create([])` wrote a row containing nothing but an id, and the endpoint still
 * answered 201, so the UI looked like it had saved. `update([])` was a silent
 * no-op for the same reason. Most of the models also had no $fillable, so once
 * the rules were filled in mass assignment would have thrown instead.
 *
 * Each case below submits real values and asserts they came back, which is what
 * neither half of that bug allowed.
 */
class ResourcePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'persist-test', 'plan' => 'enterprise']);

        $user = app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => User::factory()->create(['tenant_id' => $this->tenant->getKey()]),
        );

        Sanctum::actingAs($user);
    }

    /**
     * @param  callable():array<string, mixed>  $payload
     */
    private function assertPersists(string $endpoint, callable $payload, array $expect): void
    {
        $body = app(TenantContext::class)->runFor($this->tenant, $payload);

        $response = $this->postJson("/api/v1/{$endpoint}", $body)->assertCreated();

        foreach ($expect as $field) {
            $this->assertNotNull(
                $response->json($field),
                "{$endpoint}: [{$field}] was discarded - the row saved without it."
            );
            $this->assertEquals($body[$field], $response->json($field), "{$endpoint}: [{$field}] did not persist.");
        }
    }

    private function customer(): Customer
    {
        return app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => Customer::create(['name' => 'Walk In', 'phone' => '0170000000']),
        );
    }

    private ?Location $location = null;

    /** Memoised: slugs are unique per tenant, so a second call would collide. */
    private function location(): Location
    {
        return $this->location ??= app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => Location::create(['name' => 'Main', 'slug' => 'main', 'is_active' => true]),
        );
    }

    public function test_supplier_create_persists_its_fields(): void
    {
        $this->assertPersists('suppliers', fn () => [
            'name' => 'Dhaka Fresh Produce',
            'email' => 'sales@dhakafresh.test',
            'phone' => '+8801711223344',
            'address' => '12 Gulshan Ave',
        ], ['name', 'email', 'phone', 'address']);
    }

    public function test_reservation_create_persists_its_fields(): void
    {
        $customer = $this->customer();
        $location = $this->location();

        $this->assertPersists('reservations', fn () => [
            'customer_id' => $customer->id,
            'location_id' => $location->id,
            'guest_count' => 4,
            'status' => 'confirmed',
        ], ['customer_id', 'location_id', 'guest_count', 'status']);
    }

    public function test_purchase_order_create_persists_its_fields(): void
    {
        $supplier = app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => Supplier::create(['name' => 'Acme Wholesale']),
        );
        $location = $this->location();

        $this->assertPersists('purchase-orders', fn () => [
            'supplier_id' => $supplier->id,
            'location_id' => $location->id,
            'total_amount' => '1500.00',
            'status' => 'pending',
        ], ['supplier_id', 'location_id', 'status']);
    }

    public function test_payment_create_persists_its_fields(): void
    {
        $order = app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => Order::create(['location_id' => $this->location()->id, 'status' => 'pending', 'total' => 100]),
        );

        $this->assertPersists('payments', fn () => [
            'order_id' => $order->id,
            'method' => 'cash',
            'status' => 'paid',
        ], ['order_id', 'method', 'status']);
    }

    public function test_waste_log_create_persists_its_fields(): void
    {
        $item = app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => InventoryItem::create(['name' => 'Tomato', 'unit' => 'kg']),
        );
        $location = $this->location();

        $this->assertPersists('waste-logs', fn () => [
            'inventory_item_id' => $item->id,
            'location_id' => $location->id,
            'reason' => 'Spoiled in storage',
        ], ['inventory_item_id', 'location_id', 'reason']);
    }

    public function test_support_ticket_create_persists_its_fields(): void
    {
        $customer = $this->customer();

        $this->assertPersists('support-tickets', fn () => [
            'customer_id' => $customer->id,
            'subject' => 'Cold delivery',
            'status' => 'open',
        ], ['customer_id', 'subject', 'status']);
    }

    public function test_stock_transfer_create_persists_its_fields(): void
    {
        [$item, $from, $to] = app(TenantContext::class)->runFor($this->tenant, fn () => [
            InventoryItem::create(['name' => 'Rice', 'unit' => 'kg']),
            StorageUnit::create(['name' => 'Dry Store', 'location_id' => $this->location()->id]),
            StorageUnit::create(['name' => 'Kitchen', 'location_id' => $this->location()->id]),
        ]);

        $this->assertPersists('stock-transfers', fn () => [
            'inventory_item_id' => $item->id,
            'from_storage_id' => $from->id,
            'to_storage_id' => $to->id,
        ], ['inventory_item_id', 'from_storage_id', 'to_storage_id']);
    }

    public function test_discount_create_persists_its_fields(): void
    {
        $this->assertPersists('discounts', fn () => [
            'code' => 'EID25',
            'discount_type' => 'percentage',
            'value' => '25.00',
        ], ['code', 'discount_type']);
    }

    public function test_a_missing_required_field_is_rejected_rather_than_saved_empty(): void
    {
        // The old behaviour: 201, and a row containing only an id.
        $this->postJson('/api/v1/suppliers', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->postJson('/api/v1/reservations', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id', 'location_id']);

        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_update_is_partial_and_does_not_blank_omitted_fields(): void
    {
        $customer = $this->customer();

        $ticket = app(TenantContext::class)->runFor($this->tenant, fn () => SupportTicket::create([
            'customer_id' => $customer->id,
            'subject' => 'Original subject',
            'status' => 'open',
        ]));

        $this->putJson("/api/v1/support-tickets/{$ticket->id}", ['status' => 'closed'])
            ->assertOk()
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('subject', 'Original subject');
    }

    public function test_a_foreign_key_from_another_tenant_is_refused(): void
    {
        // tenantExists, not a plain exists rule: an unscoped rule would let a
        // request attach another restaurant's customer to its own reservation.
        $otherTenant = Tenant::factory()->create(['slug' => 'someone-else']);

        $theirCustomer = app(TenantContext::class)->runFor(
            $otherTenant,
            fn () => Customer::create(['name' => 'Not Yours']),
        );

        $this->postJson('/api/v1/reservations', [
            'customer_id' => $theirCustomer->id,
            'location_id' => $this->location()->id,
        ])->assertStatus(422)->assertJsonValidationErrors('customer_id');
    }
}
