<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Crediting a sale to the employee who made it.
 *
 * The distinction this rests on: `orders.user_id` records the account that
 * created the order, and a till is very often one shared login. Grouping sales
 * by it would report a single "POS" employee serving every customer in the
 * restaurant - a report that looks plausible and measures nothing.
 */
class StaffPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $till;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Staff Restaurant',
            'slug' => 'staff-restaurant',
            'plan' => 'enterprise',
            'status' => 'active',
        ]);

        $this->till = $this->inTenant(fn () => User::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'name' => 'Shared Till',
        ]));

        Sanctum::actingAs($this->till);
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function employee(string $name): User
    {
        return $this->inTenant(fn () => User::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'name' => $name,
        ]));
    }

    private function product(): Product
    {
        return $this->inTenant(function () {
            $category = ProductCategory::create(['name' => 'Mains']);

            return Product::create([
                'category_id' => $category->getKey(),
                'name' => 'Biryani',
                'price' => 500,
            ]);
        });
    }

    /** Places an order through the API, exactly as the till does. */
    private function placeOrder(?User $servedBy, float $amount = 500): int
    {
        $location = $this->inTenant(fn () => Location::query()->firstOrFail());

        return $this->postJson('/api/v1/orders', [
            'location_id' => $location->getKey(),
            'order_type' => 'takeaway',
            'subtotal' => $amount,
            'discount_amount' => 0,
            'served_by_user_id' => $servedBy?->getKey(),
            'items' => [['product_id' => $this->product()->getKey(), 'qty' => 1, 'price' => $amount]],
        ], ['X-Tenant-ID' => $this->tenant->slug])->assertCreated()->json('id');
    }

    #[Test]
    public function an_order_records_who_served_it_separately_from_who_rang_it_up(): void
    {
        $waiter = $this->employee('Rina');

        $id = $this->placeOrder($waiter);
        $order = Order::withoutGlobalScopes()->findOrFail($id);

        $this->assertSame($waiter->getKey(), $order->served_by_user_id);
        // The shared till login is still recorded, and is still a different
        // thing: it is the audit trail, not the credit.
        $this->assertSame($this->till->getKey(), $order->user_id);
    }

    #[Test]
    public function an_order_may_credit_nobody(): void
    {
        $id = $this->placeOrder(null);

        $this->assertNull(Order::withoutGlobalScopes()->findOrFail($id)->served_by_user_id);
    }

    #[Test]
    public function an_employee_from_another_restaurant_cannot_be_credited(): void
    {
        $other = app(TenantProvisioner::class)->create([
            'name' => 'Somebody Else',
            'slug' => 'somebody-else',
            'plan' => 'enterprise',
            'status' => 'active',
        ]);

        $stranger = app(TenantContext::class)->runFor(
            $other,
            fn () => User::factory()->create(['tenant_id' => $other->getKey()]),
        );

        $location = $this->inTenant(fn () => Location::query()->firstOrFail());

        $this->postJson('/api/v1/orders', [
            'location_id' => $location->getKey(),
            'order_type' => 'takeaway',
            'subtotal' => 500,
            'discount_amount' => 0,
            'served_by_user_id' => $stranger->getKey(),
            'items' => [['product_id' => $this->product()->getKey(), 'qty' => 1, 'price' => 500]],
        ], ['X-Tenant-ID' => $this->tenant->slug])
            ->assertStatus(422)
            ->assertJsonValidationErrors('served_by_user_id');
    }

    #[Test]
    public function the_report_groups_sales_by_employee(): void
    {
        $rina = $this->employee('Rina');
        $kamal = $this->employee('Kamal');

        $this->placeOrder($rina, 1000);
        $this->placeOrder($rina, 500);
        $this->placeOrder($kamal, 300);

        $report = $this->getJson('/api/v1/reports/staff', ['X-Tenant-ID' => $this->tenant->slug])
            ->assertOk()
            ->json();

        $byName = collect($report['employees'])->keyBy('name');

        $this->assertSame(2, $report['summary']['employees']);
        $this->assertSame(2, $byName['Rina']['orders_count']);
        $this->assertSame(1, $byName['Kamal']['orders_count']);

        // Ordered by revenue, so the strongest seller leads.
        $this->assertSame('Rina', $report['employees'][0]['name']);
        $this->assertGreaterThan(
            $byName['Kamal']['revenue'],
            $byName['Rina']['revenue'],
        );
    }

    /**
     * Uncredited sales are shown, not dropped. Hiding them would make this
     * report's total quietly disagree with the sales report over the same
     * window, and their size is what tells a manager whether staff are
     * actually tagging their sales.
     */
    #[Test]
    public function uncredited_sales_are_reported_as_their_own_row(): void
    {
        $rina = $this->employee('Rina');

        $this->placeOrder($rina, 1000);
        $this->placeOrder(null, 400);

        $report = $this->getJson('/api/v1/reports/staff', ['X-Tenant-ID' => $this->tenant->slug])
            ->assertOk()
            ->json();

        $this->assertSame(1, $report['summary']['employees']);
        $this->assertSame(1, $report['summary']['unattributed_orders']);

        $unattributed = collect($report['employees'])->firstWhere('user_id', null);

        $this->assertNotNull($unattributed);
        $this->assertSame('Not attributed', $unattributed['name']);
    }

    /**
     * An employee who leaves must not take the sales history with them.
     */
    #[Test]
    public function deleting_an_employee_keeps_their_orders(): void
    {
        $leaver = $this->employee('Leaver');
        $id = $this->placeOrder($leaver, 700);

        $this->inTenant(fn () => User::query()->whereKey($leaver->getKey())->delete());

        $order = Order::withoutGlobalScopes()->findOrFail($id);

        $this->assertNotNull($order, 'the order survived');
        $this->assertNull($order->served_by_user_id, 'and simply stopped naming anyone');
    }
}
