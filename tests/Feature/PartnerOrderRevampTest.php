<?php

namespace Tests\Feature;

use App\Models\CashStorage;
use App\Models\Location;
use App\Models\Order;
use App\Models\Partner;
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

class PartnerOrderRevampTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    private Partner $foodpanda;

    private Product $biryani;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Partner Revamp Test',
            'slug' => 'partner-revamp-test',
            'plan' => 'enterprise',
            'status' => 'active',
        ]);

        $this->inTenant(function () {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $user->assignRole(RoleDefinitions::RESTAURANT_ADMIN);
            Sanctum::actingAs($user);

            $this->location = Location::query()->firstOrFail();
            $this->foodpanda = Partner::create([
                'name' => 'Foodpanda',
                'commission_rate' => 20.00,
                'is_active' => true,
            ]);

            $category = ProductCategory::create(['name' => 'Mains']);
            $this->biryani = Product::create([
                'category_id' => $category->id,
                'name' => 'Kacchi Biryani',
                'price' => 450,
            ]);
        });
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function headers(): array
    {
        return ['X-Tenant-ID' => $this->tenant->slug];
    }

    #[Test]
    public function partner_order_records_reference_number_and_defaults_to_due(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'location_id' => $this->location->id,
            'order_type' => 'takeaway',
            'partner_id' => $this->foodpanda->id,
            'partner_order_id' => 'FP-98234',
            'subtotal' => 900,
            'discount_amount' => 0,
            'items' => [
                ['product_id' => $this->biryani->id, 'qty' => 2, 'price' => 450],
            ],
        ], $this->headers())->assertCreated();

        $orderId = $response->json('id');
        $order = Order::withoutGlobalScopes()->findOrFail($orderId);

        $this->assertSame('FP-98234', $order->partner_order_id);
        $this->assertSame(Order::PAYMENT_DUE, $order->payment_status);
        $this->assertStringContainsString('Foodpanda', (string) $order->due_note);
        $this->assertStringContainsString('FP-98234', (string) $order->due_note);
        $this->assertEquals(20.00, $order->partner_commission_rate);
        $this->assertEquals(180.00, $order->partner_commission_amount);
    }

    #[Test]
    public function partner_order_can_be_marked_as_due_without_customer_id(): void
    {
        // Create an unpaid partner order with explicit payment_method passed as null
        $order = $this->inTenant(function () {
            return Order::create([
                'location_id' => $this->location->id,
                'order_type' => 'takeaway',
                'partner_id' => $this->foodpanda->id,
                'partner_order_id' => 'FP-5555',
                'customer_id' => null,
                'subtotal' => 450,
                'total' => 450,
                'status' => 'ready',
                'payment_status' => Order::PAYMENT_UNPAID,
            ]);
        });

        $this->postJson("/api/v1/orders/{$order->id}/due", [
            'due_note' => 'Owed by Foodpanda settlement batch',
        ], $this->headers())->assertOk();

        $order->refresh();
        $this->assertSame(Order::PAYMENT_DUE, $order->payment_status);
        $this->assertSame('Owed by Foodpanda settlement batch', $order->due_note);
    }

    #[Test]
    public function orders_can_be_filtered_by_partner_id_and_partner_order_id(): void
    {
        $this->postJson('/api/v1/orders', [
            'location_id' => $this->location->id,
            'order_type' => 'takeaway',
            'partner_id' => $this->foodpanda->id,
            'partner_order_id' => 'FP-ABC-123',
            'subtotal' => 450,
            'discount_amount' => 0,
            'items' => [['product_id' => $this->biryani->id, 'qty' => 1, 'price' => 450]],
        ], $this->headers())->assertCreated();

        $this->postJson('/api/v1/orders', [
            'location_id' => $this->location->id,
            'order_type' => 'dine_in',
            'partner_id' => null,
            'subtotal' => 450,
            'discount_amount' => 0,
            'items' => [['product_id' => $this->biryani->id, 'qty' => 1, 'price' => 450]],
        ], $this->headers())->assertCreated();

        // Query with partner_id filter
        $resPartner = $this->getJson("/api/v1/orders?partner_id={$this->foodpanda->id}", $this->headers())
            ->assertOk();
        $this->assertCount(1, $resPartner->json('data'));
        $this->assertSame('FP-ABC-123', $resPartner->json('data.0.partner_order_id'));

        // Query with partner_order_id search
        $resRef = $this->getJson('/api/v1/orders?partner_order_id=ABC-123', $this->headers())
            ->assertOk();
        $this->assertCount(1, $resRef->json('data'));
        $this->assertSame('FP-ABC-123', $resRef->json('data.0.partner_order_id'));
    }

    #[Test]
    public function sales_report_breaks_down_direct_versus_partner_channels(): void
    {
        // 1. Partner order: 1000 gross, 20% commission = 200 commission, 800 net
        $this->postJson('/api/v1/orders', [
            'location_id' => $this->location->id,
            'order_type' => 'takeaway',
            'partner_id' => $this->foodpanda->id,
            'partner_order_id' => 'FP-REPORT-1',
            'subtotal' => 1000,
            'discount_amount' => 0,
            'items' => [['product_id' => $this->biryani->id, 'qty' => 2, 'price' => 500]],
        ], $this->headers())->assertCreated();

        // 2. Direct order: 500 gross
        $this->postJson('/api/v1/orders', [
            'location_id' => $this->location->id,
            'order_type' => 'dine_in',
            'partner_id' => null,
            'payment_method' => 'cash',
            'subtotal' => 500,
            'discount_amount' => 0,
            'items' => [['product_id' => $this->biryani->id, 'qty' => 1, 'price' => 500]],
        ], $this->headers())->assertCreated();

        // Query full sales report
        $allRes = $this->getJson('/api/v1/reports/sales', $this->headers())->assertOk();
        $summary = $allRes->json('summary');

        $this->assertSame(2, $summary['orders_count']);
        $this->assertSame(1, $summary['partner_orders_count']);
        $this->assertEquals(1000.0, $summary['partner_gross_revenue']);
        $this->assertEquals(200.0, $summary['partner_commission_total']);
        $this->assertEquals(800.0, $summary['partner_net_revenue']);
        $this->assertSame(1, $summary['direct_orders_count']);
        $this->assertEquals(500.0, $summary['direct_gross_revenue']);

        // Query filtered channel=partner
        $partnerOnlyRes = $this->getJson('/api/v1/reports/sales?channel=partner', $this->headers())->assertOk();
        $this->assertSame(1, $partnerOnlyRes->json('summary.orders_count'));
        $this->assertEquals(1000.0, $partnerOnlyRes->json('summary.gross_revenue'));

        // Query filtered channel=direct
        $directOnlyRes = $this->getJson('/api/v1/reports/sales?channel=direct', $this->headers())->assertOk();
        $this->assertSame(1, $directOnlyRes->json('summary.orders_count'));
        $this->assertEquals(500.0, $directOnlyRes->json('summary.gross_revenue'));
    }

    #[Test]
    public function batch_payout_settles_selected_orders_and_updates_payment_status(): void
    {
        // Create 2 due orders for foodpanda
        $order1 = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'order_type' => 'takeaway',
            'partner_id' => $this->foodpanda->id,
            'partner_order_id' => 'FP-BATCH-1',
            'partner_commission_rate' => 20,
            'partner_commission_amount' => 200,
            'total' => 1000,
            'payment_status' => Order::PAYMENT_DUE,
            'status' => 'completed',
        ]);

        $order2 = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'order_type' => 'takeaway',
            'partner_id' => $this->foodpanda->id,
            'partner_order_id' => 'FP-BATCH-2',
            'partner_commission_rate' => 20,
            'partner_commission_amount' => 100,
            'total' => 500,
            'payment_status' => Order::PAYMENT_DUE,
            'status' => 'completed',
        ]);

        // Batch payout for both orders
        $res = $this->postJson('/api/v1/partner-payouts', [
            'partner_id' => $this->foodpanda->id,
            'order_ids' => [$order1->id, $order2->id],
            'payment_method' => 'bank_transfer',
            'received_on' => '2026-11-23',
            'reference' => 'STMT-NOV-2026',
            'note' => 'Monthly settlement for 2 orders',
        ], $this->headers())->assertCreated();

        $payoutId = $res->json('id');
        $this->assertSame(2, $res->json('orders_count'));
        $this->assertSame('bank_transfer', $res->json('payment_method'));
        // Net amount is 800 + 400 = 1200
        $this->assertEquals(1200.0, (float) $res->json('amount'));

        // Refresh orders and assert they are marked paid and linked to payout
        $order1->refresh();
        $order2->refresh();

        $this->assertSame(Order::PAYMENT_PAID, $order1->payment_status);
        $this->assertSame($payoutId, $order1->partner_payout_id);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order1->id,
            'method' => 'bank_transfer',
            'status' => 'completed',
        ]);

        $this->assertSame(Order::PAYMENT_PAID, $order2->payment_status);
        $this->assertSame($payoutId, $order2->partner_payout_id);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order2->id,
            'method' => 'bank_transfer',
            'status' => 'completed',
        ]);

        // Accounting ledger entry created with details
        $this->assertDatabaseHas('accounting_ledgers', [
            'transaction_type' => 'partner_payout',
            'reference_id' => $payoutId,
            'amount' => 1200,
        ]);
    }

    #[Test]
    public function batch_payout_with_cash_storage_increments_storage_balance(): void
    {
        $storage = CashStorage::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'name' => 'City Bank Current Account',
            'type' => 'card',
            'current_balance' => 5000,
            'is_active' => true,
        ]);

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'order_type' => 'takeaway',
            'partner_id' => $this->foodpanda->id,
            'partner_order_id' => 'FP-STORAGE-1',
            'partner_commission_rate' => 20,
            'partner_commission_amount' => 100,
            'total' => 500,
            'payment_status' => Order::PAYMENT_DUE,
            'status' => 'completed',
        ]);

        $this->postJson('/api/v1/partner-payouts', [
            'partner_id' => $this->foodpanda->id,
            'order_ids' => [$order->id],
            'payment_method' => 'bank_transfer',
            'cash_storage_id' => $storage->id,
            'received_on' => '2026-11-23',
            'reference' => 'TXN-BANK-991',
        ], $this->headers())->assertCreated();

        // Storage balance should increase by net amount (400)
        $storage->refresh();
        $this->assertEquals(5400.0, (float) $storage->current_balance);
    }

    #[Test]
    public function deleting_batch_payout_reverts_orders_to_due_and_cleans_payments(): void
    {
        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'order_type' => 'takeaway',
            'partner_id' => $this->foodpanda->id,
            'total' => 600,
            'partner_commission_amount' => 100,
            'payment_status' => Order::PAYMENT_DUE,
            'status' => 'completed',
        ]);

        $res = $this->postJson('/api/v1/partner-payouts', [
            'partner_id' => $this->foodpanda->id,
            'order_ids' => [$order->id],
            'payment_method' => 'bank_transfer',
            'received_on' => '2026-11-23',
        ], $this->headers())->assertCreated();

        $payoutId = $res->json('id');
        $order->refresh();
        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);

        // Delete payout
        $this->deleteJson("/api/v1/partner-payouts/{$payoutId}", [], $this->headers())
            ->assertNoContent();

        // Order reverted to due and payout unlinked
        $order->refresh();
        $this->assertSame(Order::PAYMENT_DUE, $order->payment_status);
        $this->assertNull($order->partner_payout_id);
        $this->assertDatabaseMissing('partner_payouts', ['id' => $payoutId]);
        $this->assertDatabaseMissing('accounting_ledgers', ['transaction_type' => 'partner_payout', 'reference_id' => $payoutId]);
    }
}
