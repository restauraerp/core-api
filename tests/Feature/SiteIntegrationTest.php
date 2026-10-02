<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A restaurant website's tags (GTM, GA4, Meta pixel, Clarity) and the
 * server-side conversions behind them (Meta Conversions API, GA4 Measurement
 * Protocol) for online orders and table requests.
 */
class SiteIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['events_received' => 1])]);

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Spice Garden', 'slug' => 'spice', 'plan' => 'growth', 'status' => 'active',
        ]);

        $this->product = $this->inTenant(fn () => Product::create(['name' => 'Biryani', 'price' => 500, 'type' => 'food', 'is_active' => true]));

        Sanctum::actingAs($this->inTenant(function () {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $user->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

            return $user;
        }));
        $this->withHeader('X-Tenant-ID', 'spice');
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function connectEverything(): void
    {
        $this->putJson('/api/v1/site-builder/integrations', [
            'gtm_id' => 'GTM-ABC1234',
            'ga4_id' => 'G-XYZ987',
            'meta_pixel_id' => '1234567890123456',
            'clarity_id' => 'abc12de3fg',
            'meta_capi_token' => 'EAAB-secret_token',
            'meta_test_event_code' => 'TEST123',
            'ga4_api_secret' => 'ga-secret',
        ])->assertOk();
    }

    private function placeOrder(): int
    {
        return $this->postJson('/api/v1/order/spice', [
            'order_type' => 'takeaway',
            'name' => 'Rahim Uddin',
            'phone' => '01711223344',
            'items' => [['product_id' => $this->product->id, 'qty' => 2]],
            'tracking' => [
                'fbp' => 'fb.1.1700000000.111',
                'fbc' => 'fb.1.1700000000.AbCd',
                'ga_client_id' => '123456.7890',
                'page_url' => 'https://spice.restauraerp.com/order',
            ],
        ])->assertCreated()->json('order_id');
    }

    #[Test]
    public function tags_are_checked_and_secrets_never_come_back(): void
    {
        $this->connectEverything();

        $this->getJson('/api/v1/site-builder/integrations')
            ->assertOk()
            ->assertJsonPath('gtm_id', 'GTM-ABC1234')
            ->assertJsonPath('has_meta_capi_token', true)
            ->assertJsonPath('sends_meta', true)
            ->assertJsonPath('sends_ga4', true)
            ->assertJsonMissingPath('meta_capi_token')
            ->assertJsonMissingPath('ga4_api_secret');

        // Encrypted at rest.
        $raw = \DB::table('site_integrations')->value('meta_capi_token');
        $this->assertStringNotContainsString('secret_token', $raw);

        $this->putJson('/api/v1/site-builder/integrations', [
            'gtm_id' => 'GTM-<script>',
            'ga4_id' => 'UA-12345-1',
            'meta_pixel_id' => 'abc',
            'clarity_id' => 'Has Spaces',
        ])->assertUnprocessable()->assertJsonValidationErrors(['gtm_id', 'ga4_id', 'meta_pixel_id', 'clarity_id']);

        // A save without the secrets keeps them; an empty one clears it.
        $this->putJson('/api/v1/site-builder/integrations', ['gtm_id' => 'GTM-NEW1234'])->assertJsonPath('has_meta_capi_token', true);
        $this->putJson('/api/v1/site-builder/integrations', ['meta_capi_token' => ''])->assertJsonPath('sends_meta', false);
    }

    #[Test]
    public function the_site_and_ordering_load_the_tags_but_never_the_secrets(): void
    {
        $this->connectEverything();

        $site = $this->getJson('/api/v1/site/spice')
            ->assertOk()
            ->assertJsonPath('tracking.gtm_id', 'GTM-ABC1234')
            ->assertJsonPath('tracking.clarity_id', 'abc12de3fg')
            // GA4 purchases go server-side, so the browser must not send them too.
            ->assertJsonPath('tracking.ga4_server', true);
        $this->assertStringNotContainsString('secret', $site->getContent());

        $order = $this->getJson('/api/v1/order/spice')->assertJsonPath('tracking.meta_pixel_id', '1234567890123456');
        $this->assertStringNotContainsString('secret', $order->getContent());
    }

    #[Test]
    public function an_online_order_is_reported_to_meta_and_google(): void
    {
        $this->connectEverything();
        $orderId = $this->placeOrder();

        Http::assertSent(function (HttpRequest $request) use ($orderId) {
            if (! str_contains($request->url(), 'graph.facebook.com') || ! str_ends_with($request->url(), '/1234567890123456/events')) {
                return false;
            }
            $event = $request['data'][0];

            return $event['event_name'] === 'Purchase'
                && $event['event_id'] === "order-{$orderId}"
                && $event['custom_data']['value'] == 1000
                && $event['custom_data']['currency'] === 'BDT'
                // Hashed, with the country code, as Meta asks.
                && $event['user_data']['ph'] === [hash('sha256', '8801711223344')]
                && $event['user_data']['fn'] === [hash('sha256', 'rahim')]
                && $event['user_data']['fbp'] === 'fb.1.1700000000.111'
                && $request['test_event_code'] === 'TEST123'
                && $request['access_token'] === 'EAAB-secret_token';
        });

        Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://www.google-analytics.com/mp/collect')
            && str_contains($request->url(), 'measurement_id=G-XYZ987')
            && $request['client_id'] === '123456.7890'
            && $request['events'][0]['name'] === 'purchase'
            && $request['events'][0]['params']['transaction_id'] === (string) $orderId
            && $request['events'][0]['params']['items'][0]['quantity'] === 2);

        // No phone number in the clear, anywhere.
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->body(), '01711223344'));
    }

    #[Test]
    public function a_table_request_is_reported_as_a_lead(): void
    {
        $this->connectEverything();
        $location = $this->inTenant(fn () => Location::query()->firstOrFail());

        $id = $this->postJson('/api/v1/site/spice/reservations', [
            'name' => 'Rina', 'phone' => '01811223344', 'location_id' => $location->id,
            'date' => now()->addDay()->format('Y-m-d'), 'time' => '19:30', 'guests' => 2,
        ])->assertCreated()->json('reservation_id');

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'graph.facebook.com')
            && $request['data'][0]['event_name'] === 'Lead'
            && $request['data'][0]['event_id'] === "reservation-{$id}");
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'mp/collect')
            && $request['events'][0]['name'] === 'generate_lead');
    }

    #[Test]
    public function nothing_is_sent_without_server_side_setup(): void
    {
        // Browser tags only - no token, no API secret.
        $this->putJson('/api/v1/site-builder/integrations', ['meta_pixel_id' => '1234567890123456', 'ga4_id' => 'G-XYZ987'])->assertOk();
        $this->placeOrder();

        Http::assertNothingSent();
        $this->getJson('/api/v1/site/spice')->assertJsonPath('tracking.ga4_server', false);
    }

    #[Test]
    public function a_failing_ad_platform_never_fails_the_order(): void
    {
        $this->connectEverything();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid token']], 400)]);

        // The sync queue in tests runs the job inline; it fails, the order does not.
        $this->withoutExceptionHandling();
        try {
            $this->placeOrder();
        } catch (\Throwable $e) {
            $this->fail('The order failed because the ad platform did: '.$e->getMessage());
        }

        $this->assertSame(1, $this->inTenant(fn () => Order::count()));
        $this->assertTrue(SiteIntegration::withoutGlobalScopes()->exists());
    }
}
