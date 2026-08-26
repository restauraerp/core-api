<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Orders\TokenNumber;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The number the counter calls out.
 *
 * Two things are easy to get wrong here and expensive to notice late: the day
 * breaking at midnight instead of 00:15 (which splits one evening's service
 * across two runs of tokens), and two tills being handed the same number.
 */
class OrderTokenNumberTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $branchOne;

    private Location $branchTwo;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'token-test', 'plan' => 'enterprise']);

        $user = app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => User::factory()->create(['tenant_id' => $this->tenant->getKey()]),
        );

        [$this->branchOne, $this->branchTwo, $this->product] = app(TenantContext::class)->runFor($this->tenant, fn () => [
            Location::create(['name' => 'Gulshan', 'slug' => 'gulshan', 'is_active' => true]),
            Location::create(['name' => 'Banani', 'slug' => 'banani', 'is_active' => true]),
            Product::create(['name' => 'Biryani', 'price' => 500, 'type' => 'food']),
        ]);

        Sanctum::actingAs($user);
    }

    private function placeOrder(?Location $at = null): int
    {
        $response = $this->postJson('/api/v1/orders', [
            'location_id' => ($at ?? $this->branchOne)->id,
            'order_type' => 'dine_in',
            'subtotal' => '500.00',
            'discount_amount' => '0.00',
            'items' => [[
                'product_id' => $this->product->id,
                'qty' => 1,
                'price' => '500.00',
            ]],
        ])->assertCreated();

        return (int) $response->json('token_number');
    }

    public function test_tokens_start_at_one_and_count_up(): void
    {
        $this->assertSame(1, $this->placeOrder());
        $this->assertSame(2, $this->placeOrder());
        $this->assertSame(3, $this->placeOrder());
    }

    public function test_each_branch_runs_its_own_sequence(): void
    {
        $this->assertSame(1, $this->placeOrder($this->branchOne));
        $this->assertSame(2, $this->placeOrder($this->branchOne));

        // A second outlet is a second counter, calling out its own numbers.
        $this->assertSame(1, $this->placeOrder($this->branchTwo));
        $this->assertSame(2, $this->placeOrder($this->branchTwo));

        $this->assertSame(3, $this->placeOrder($this->branchOne));
    }

    public function test_the_run_resets_the_next_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 19:00:00'));
        $this->assertSame(1, $this->placeOrder());
        $this->assertSame(2, $this->placeOrder());

        Carbon::setTestNow(Carbon::parse('2026-03-02 11:00:00'));
        $this->assertSame(1, $this->placeOrder());
    }

    /**
     * The whole point of 00:15 rather than 00:00: a restaurant still serving
     * after midnight is still working the same evening, and its tokens should
     * carry on rather than restart mid-service.
     */
    public function test_the_day_breaks_at_quarter_past_midnight_not_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 23:50:00'));
        $this->assertSame(1, $this->placeOrder());

        // Ten past midnight - still the 1st's service.
        Carbon::setTestNow(Carbon::parse('2026-03-02 00:10:00'));
        $this->assertSame(2, $this->placeOrder());

        // 00:15 exactly is where the new day starts.
        Carbon::setTestNow(Carbon::parse('2026-03-02 00:15:00'));
        $this->assertSame(1, $this->placeOrder());

        Carbon::setTestNow(Carbon::parse('2026-03-02 00:20:00'));
        $this->assertSame(2, $this->placeOrder());
    }

    public function test_an_after_midnight_order_is_filed_under_the_day_that_is_closing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-02 00:10:00'));
        $this->placeOrder();

        $this->assertSame(
            '2026-03-01',
            Carbon::parse(DB::table('orders')->value('business_date'))->toDateString(),
        );
    }

    public function test_a_token_is_never_handed_out_twice_in_a_day(): void
    {
        $tokens = [];
        for ($i = 0; $i < 25; $i++) {
            $tokens[] = $this->placeOrder();
        }

        $this->assertSame(range(1, 25), $tokens);
        $this->assertCount(25, array_unique($tokens));
    }

    /**
     * A rolled-back order must not burn a number - the run would show a gap the
     * counter never called.
     */
    public function test_a_failed_order_does_not_consume_a_number(): void
    {
        $this->assertSame(1, $this->placeOrder());

        // Refused by validation: the product does not belong to this tenant.
        $this->postJson('/api/v1/orders', [
            'location_id' => $this->branchOne->id,
            'order_type' => 'dine_in',
            'subtotal' => '500.00',
            'discount_amount' => '0.00',
            'items' => [['product_id' => 999999, 'qty' => 1, 'price' => '500.00']],
        ])->assertStatus(422);

        $this->assertSame(2, $this->placeOrder());
    }

    public function test_existing_orders_are_numbered_by_when_they_were_taken(): void
    {
        // Rows written straight to the table, as the demo seeder does, out of
        // chronological order on purpose.
        app(TenantContext::class)->runFor($this->tenant, function () {
            foreach (['2026-04-01 20:00:00', '2026-04-01 12:00:00', '2026-04-01 16:00:00'] as $at) {
                DB::table('orders')->insert([
                    'tenant_id' => $this->tenant->getKey(),
                    'location_id' => $this->branchOne->id,
                    'order_type' => 'dine_in',
                    'status' => 'served',
                    'subtotal' => 100, 'tax_amount' => 0, 'discount_amount' => 0, 'total' => 100,
                    'created_at' => $at, 'updated_at' => $at,
                ]);
            }
        });

        TokenNumber::numberExistingOrders();

        $numbered = DB::table('orders')->orderBy('created_at')->pluck('token_number')->all();
        $this->assertSame([1, 2, 3], array_map('intval', $numbered));

        // And the counter carries on from there rather than reissuing 1.
        Carbon::setTestNow(Carbon::parse('2026-04-01 21:00:00'));
        $this->assertSame(4, $this->placeOrder());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
