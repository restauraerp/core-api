<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Orders\OrderFlow;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompletedOrderSortTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Sort Test Restaurant',
            'slug' => 'sort-test-restaurant',
            'plan' => 'enterprise',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->inTenant(
            fn () => User::factory()->create(['tenant_id' => $this->tenant->getKey()]),
        ));
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    public function test_completed_orders_sort_by_token(): void
    {
        $this->inTenant(function () {
            $location = Location::query()->firstOrFail();

            Order::create([
                'location_id' => $location->id,
                'order_type' => OrderFlow::DINE_IN,
                'status' => OrderFlow::SERVED,
                'payment_status' => Order::PAYMENT_PAID,
                'subtotal' => 100,
                'total' => 100,
                'business_date' => '2026-11-19',
                'token_number' => 20,
                'created_at' => now()->subMinutes(10),
            ]);

            Order::create([
                'location_id' => $location->id,
                'order_type' => OrderFlow::DINE_IN,
                'status' => OrderFlow::SERVED,
                'payment_status' => Order::PAYMENT_PAID,
                'subtotal' => 100,
                'total' => 100,
                'business_date' => '2026-11-19',
                'token_number' => 5,
                'created_at' => now()->subMinutes(5),
            ]);
        });

        // Test token ascending (1 -> 99)
        $resAsc = $this->getJson('/api/v1/orders?completed_only=1&sort=token')->assertOk();
        $tokensAsc = collect($resAsc->json('data'))->pluck('token_number')->all();
        $this->assertSame([5, 20], $tokensAsc);

        // Test token descending (99 -> 1)
        $resDesc = $this->getJson('/api/v1/orders?completed_only=1&sort=token_desc')->assertOk();
        $tokensDesc = collect($resDesc->json('data'))->pluck('token_number')->all();
        $this->assertSame([20, 5], $tokensDesc);
    }

    public function test_completed_orders_sort_by_recent_and_oldest(): void
    {
        [$earlyId, $lateId] = $this->inTenant(function () {
            $location = Location::query()->firstOrFail();

            $early = Order::create([
                'location_id' => $location->id,
                'order_type' => OrderFlow::DINE_IN,
                'status' => OrderFlow::SERVED,
                'payment_status' => Order::PAYMENT_PAID,
                'subtotal' => 100,
                'total' => 100,
                'created_at' => '2026-11-19 10:00:00',
            ]);

            $late = Order::create([
                'location_id' => $location->id,
                'order_type' => OrderFlow::DINE_IN,
                'status' => OrderFlow::SERVED,
                'payment_status' => Order::PAYMENT_PAID,
                'subtotal' => 100,
                'total' => 100,
                'created_at' => '2026-11-19 12:00:00',
            ]);

            return [$early->id, $late->id];
        });

        // Recent (newest first)
        $resRecent = $this->getJson('/api/v1/orders?completed_only=1&sort=recent')->assertOk();
        $idsRecent = collect($resRecent->json('data'))->pluck('id')->all();
        $this->assertSame([$lateId, $earlyId], $idsRecent);

        // Oldest (oldest first)
        $resOldest = $this->getJson('/api/v1/orders?completed_only=1&sort=oldest')->assertOk();
        $idsOldest = collect($resOldest->json('data'))->pluck('id')->all();
        $this->assertSame([$earlyId, $lateId], $idsOldest);
    }
}
