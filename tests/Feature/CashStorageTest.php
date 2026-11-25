<?php

namespace Tests\Feature;

use App\Models\CashStorage;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CashStorageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    private User $admin;

    private Product $burger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Cash Org Restaurant',
            'slug' => 'cash-org-restaurant',
            'plan' => 'enterprise',
            'status' => 'active',
        ]);

        $this->inTenant(function () {
            $this->admin = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $this->admin->assignRole(RoleDefinitions::RESTAURANT_ADMIN);
            Sanctum::actingAs($this->admin);

            $this->location = Location::query()->firstOrFail();

            $category = ProductCategory::create(['name' => 'Fast Food']);
            $this->burger = Product::create([
                'category_id' => $category->id,
                'name' => 'Beef Cheeseburger',
                'price' => 350,
            ]);
        });
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    #[Test]
    public function tenant_provisioning_seeds_standard_default_cash_storages(): void
    {
        $this->inTenant(function () {
            $storages = CashStorage::all();

            $this->assertCount(3, $storages);
            $this->assertTrue($storages->contains('type', CashStorage::TYPE_CASH));
            $this->assertTrue($storages->contains('type', CashStorage::TYPE_CARD));
            $this->assertTrue($storages->contains('type', CashStorage::TYPE_BKASH));
        });
    }

    #[Test]
    public function can_create_list_update_and_delete_cash_storage(): void
    {
        $this->inTenant(function () {
            // 1. Create a custom cash drawer for Register 2
            $response = $this->postJson('/api/v1/cash-storages', [
                'name' => 'Register 2 Till',
                'type' => 'cash',
                'location_id' => $this->location->id,
                'opening_balance' => 500.00,
                'notes' => 'Float of 500 for morning shift',
            ]);

            $response->assertStatus(201)
                ->assertJsonPath('name', 'Register 2 Till')
                ->assertJsonPath('type', 'cash')
                ->assertJsonPath('opening_balance', '500.00')
                ->assertJsonPath('current_balance', '500.00');

            $id = $response->json('id');

            // 2. Index listing
            $this->getJson('/api/v1/cash-storages?type=cash')
                ->assertOk()
                ->assertJsonFragment(['name' => 'Register 2 Till']);

            // 3. Update storage
            $this->putJson("/api/v1/cash-storages/{$id}", [
                'name' => 'Register 2 Till (Evening)',
                'notes' => 'Updated notes',
            ])->assertOk()
                ->assertJsonPath('name', 'Register 2 Till (Evening)');

            // 4. Soft delete
            $this->deleteJson("/api/v1/cash-storages/{$id}")
                ->assertStatus(204);

            $this->assertSoftDeleted('cash_storages', ['id' => $id]);
        });
    }

    #[Test]
    public function summary_endpoint_aggregates_totals_by_storage_type(): void
    {
        $this->inTenant(function () {
            // Set some balances
            CashStorage::query()->where('type', 'cash')->first()->update(['current_balance' => 3500]);
            CashStorage::query()->where('type', 'card')->first()->update(['current_balance' => 7000]);
            CashStorage::query()->where('type', 'bkash')->first()->update(['current_balance' => 4500]);

            $response = $this->getJson('/api/v1/cash-storages/summary');

            $response->assertOk()
                ->assertJsonPath('cash_total', 3500)
                ->assertJsonPath('card_total', 7000)
                ->assertJsonPath('bkash_total', 4500)
                ->assertJsonPath('grand_total', 15000);
        });
    }

    #[Test]
    public function order_payment_routes_to_cash_storage_and_increments_balance(): void
    {
        $this->inTenant(function () {
            $cashDrawer = CashStorage::query()->where('type', 'cash')->first();
            $initialBalance = (float) $cashDrawer->current_balance;

            $payload = [
                'location_id' => $this->location->id,
                'order_type' => 'dine_in',
                'subtotal' => 700,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'delivery_charge' => 0,
                'total' => 700,
                'payment_method' => 'cash',
                'cash_storage_id' => $cashDrawer->id,
                'items' => [
                    [
                        'product_id' => $this->burger->id,
                        'qty' => 2,
                        'price' => 350,
                    ],
                ],
            ];

            $response = $this->postJson('/api/v1/orders', $payload);
            $response->assertStatus(201);

            $orderId = $response->json('id');
            $this->assertDatabaseHas('payments', [
                'order_id' => $orderId,
                'method' => 'cash',
                'amount' => 700,
                'cash_storage_id' => $cashDrawer->id,
            ]);

            $this->assertEquals($initialBalance + 700, (float) $cashDrawer->fresh()->current_balance);
        });
    }

    #[Test]
    public function payment_without_explicit_storage_id_auto_resolves_matching_storage_type(): void
    {
        $this->inTenant(function () {
            $bkashWallet = CashStorage::query()->where('type', 'bkash')->first();
            $initialBalance = (float) $bkashWallet->current_balance;

            // 1. Create order awaiting payment
            $order = Order::create([
                'location_id' => $this->location->id,
                'order_type' => 'takeaway',
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'subtotal' => 350,
                'total' => 350,
            ]);

            // 2. Collect via bkash without sending cash_storage_id
            $response = $this->putJson("/api/v1/orders/{$order->id}", [
                'payment_method' => 'bkash',
                'payment_note' => 'TrxID BKS88912',
            ]);

            $response->assertOk()
                ->assertJsonPath('payment_status', 'paid');

            $this->assertDatabaseHas('payments', [
                'order_id' => $order->id,
                'method' => 'bkash',
                'cash_storage_id' => $bkashWallet->id,
            ]);

            $this->assertEquals($initialBalance + 350, (float) $bkashWallet->fresh()->current_balance);
        });
    }

    #[Test]
    public function due_settlement_updates_storage_balance(): void
    {
        $this->inTenant(function () {
            $cardStorage = CashStorage::query()->where('type', 'card')->first();
            $initialBalance = (float) $cardStorage->current_balance;

            $order = Order::create([
                'location_id' => $this->location->id,
                'order_type' => 'takeaway',
                'status' => 'served',
                'payment_status' => Order::PAYMENT_DUE,
                'due_note' => 'Guest room 101',
                'subtotal' => 350,
                'total' => 350,
            ]);

            $response = $this->postJson("/api/v1/orders/{$order->id}/settle", [
                'amount' => 350,
                'method' => 'card',
                'cash_storage_id' => $cardStorage->id,
                'note' => 'Visa ending 9921',
            ]);

            $response->assertOk();

            $this->assertDatabaseHas('payments', [
                'order_id' => $order->id,
                'method' => 'card',
                'amount' => 350,
                'cash_storage_id' => $cardStorage->id,
            ]);

            $this->assertEquals($initialBalance + 350, (float) $cardStorage->fresh()->current_balance);
        });
    }
}
