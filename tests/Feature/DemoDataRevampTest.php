<?php

namespace Tests\Feature;

use App\Models\CashStorage;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Partner;
use App\Models\PartnerPayout;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\DemoEmployeeSeeder;
use Database\Seeders\OrderSeeder;
use Database\Seeders\PartnerSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DemoDataRevampTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Demo Revamp Test Restaurant',
            'slug' => 'demo-revamp-test',
            'plan' => 'enterprise',
            'status' => 'active',
            'is_demo' => true,
        ]);
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    #[Test]
    public function partner_seeder_creates_active_aggregators_with_commissions(): void
    {
        $this->inTenant(function () {
            $this->seed(PartnerSeeder::class);

            $this->assertDatabaseHas('partners', [
                'tenant_id' => $this->tenant->id,
                'name' => 'Foodpanda',
                'commission_rate' => 20.00,
                'is_active' => 1,
            ]);

            $this->assertDatabaseHas('partners', [
                'tenant_id' => $this->tenant->id,
                'name' => 'Pathao Food',
                'commission_rate' => 15.00,
                'is_active' => 1,
            ]);

            $this->assertDatabaseHas('partners', [
                'tenant_id' => $this->tenant->id,
                'name' => 'HungryNaki',
                'commission_rate' => 18.00,
                'is_active' => 1,
            ]);
        });
    }

    #[Test]
    public function demo_employee_seeder_creates_login_free_waiter_accounts(): void
    {
        $this->inTenant(function () {
            $this->seed(DemoEmployeeSeeder::class);

            // Verify waiters seeded without login
            $waiters = User::where('tenant_id', $this->tenant->id)
                ->where('can_login', false)
                ->get();

            $this->assertGreaterThanOrEqual(5, $waiters->count());

            foreach ($waiters as $waiter) {
                $this->assertNull($waiter->email);
                $this->assertFalse((bool) $waiter->can_login);
                $this->assertTrue($waiter->hasRole('waiter'));
            }
        });
    }

    #[Test]
    public function order_seeder_creates_orders_with_discounts_due_notes_and_partner_orders(): void
    {
        $this->inTenant(function () {
            // Setup prerequisites for OrderSeeder
            $admin = User::factory()->create([
                'tenant_id' => $this->tenant->id,
                'email' => 'admin@demo.com',
            ]);
            $admin->assignRole('restaurant_admin');

            $location = Location::firstOrFail();
            $category = ProductCategory::create(['name' => 'Biryani & Rice']);

            Product::create(['category_id' => $category->id, 'name' => 'Kacchi Biryani', 'price' => 450, 'needs_cooking' => true]);
            Product::create(['category_id' => $category->id, 'name' => 'Morog Polao', 'price' => 380, 'needs_cooking' => true]);
            Product::create(['category_id' => $category->id, 'name' => 'Borhani', 'price' => 80, 'needs_cooking' => false]);

            Customer::create(['name' => 'Demo Customer 1', 'phone' => '01711111111', 'address' => 'Gulshan 2, Dhaka']);
            Customer::create(['name' => 'Corporate Beximco', 'phone' => '01711222222', 'address' => 'Dhanmondi, Dhaka']);

            Table::create(['location_id' => $location->id, 'name' => 'Table 1', 'capacity' => 4]);
            Table::create(['location_id' => $location->id, 'name' => 'Table 2', 'capacity' => 2]);

            $this->seed(PartnerSeeder::class);
            $this->seed(AccountingSeeder::class);

            // Run OrderSeeder
            $this->seed(OrderSeeder::class);

            // 1. Verify Discount orders exist with discount_amount > 0
            $discountOrders = Order::where('tenant_id', $this->tenant->id)
                ->where('discount_amount', '>', 0)
                ->get();
            $this->assertGreaterThan(0, $discountOrders->count(), 'Expected demo orders to include discounts.');

            // 2. Verify Due orders exist with payment_status = due and due_note set
            $dueOrders = Order::where('tenant_id', $this->tenant->id)
                ->where('payment_status', 'due')
                ->get();
            $this->assertGreaterThan(0, $dueOrders->count(), 'Expected demo orders to include due orders.');
            $this->assertNotNull($dueOrders->first()->due_note);

            // 3. Verify 3rd party aggregator orders exist with partner details
            $partnerOrders = Order::where('tenant_id', $this->tenant->id)
                ->whereNotNull('partner_id')
                ->get();
            $this->assertGreaterThan(0, $partnerOrders->count(), 'Expected demo orders to include 3rd party partner orders.');

            $samplePartnerOrder = $partnerOrders->first();
            $this->assertNotNull($samplePartnerOrder->partner_order_id);
            $this->assertGreaterThan(0, $samplePartnerOrder->partner_commission_rate);
            $this->assertGreaterThan(0, $samplePartnerOrder->partner_commission_amount);

            // 4. Verify Payments are linked to CashStorages
            $paymentsWithStorage = Payment::where('tenant_id', $this->tenant->id)
                ->whereNotNull('cash_storage_id')
                ->get();
            $this->assertGreaterThan(0, $paymentsWithStorage->count(), 'Expected payments to link to cash storages.');

            // 5. Verify Partner Payouts exist for historical aggregator orders
            $payouts = PartnerPayout::where('tenant_id', $this->tenant->id)->get();
            $this->assertGreaterThan(0, $payouts->count(), 'Expected partner payouts to be seeded for demo history.');

            // 6. Verify CashStorages have non-zero active balances
            $storages = CashStorage::where('tenant_id', $this->tenant->id)->get();
            foreach ($storages as $storage) {
                $this->assertGreaterThan(0, (float) $storage->current_balance);
            }
        });
    }
}
