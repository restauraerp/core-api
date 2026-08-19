<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sending a customer their invoice over WhatsApp.
 *
 * The customer has no account and never will, so the link carries its own
 * authority: Laravel signs the order id and the expiry, and the signature is
 * the whole credential. Everything worth testing here is about what that
 * signature does and does not permit.
 */
class InvoiceSharingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['platform.app_url' => 'https://app.restauraerp.test']);

        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Invoice Restaurant',
            'slug' => 'invoice-restaurant',
            'plan' => 'enterprise',
            'status' => 'active',
        ]);
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function actAsStaff(): void
    {
        Sanctum::actingAs($this->inTenant(
            fn () => User::factory()->create(['tenant_id' => $this->tenant->getKey()]),
        ));
    }

    private function order(?Customer $customer = null): Order
    {
        return $this->inTenant(fn () => Order::create([
            'location_id' => Location::query()->firstOrFail()->getKey(),
            'customer_id' => $customer?->getKey(),
            'order_type' => 'takeaway',
            'status' => 'packed',
            'payment_status' => 'paid',
            'subtotal' => 500,
            'total' => 500,
        ]));
    }

    /** @return array{0: Order, 1: string} the order and the query it was signed with */
    private function sharedOrder(?Customer $customer = null): array
    {
        $this->actAsStaff();
        $order = $this->order($customer);

        $url = $this->postJson("/api/v1/orders/{$order->getKey()}/invoice-link", [], [
            'X-Tenant-ID' => $this->tenant->slug,
        ])->assertOk()->json('url');

        return [$order, parse_url($url, PHP_URL_QUERY)];
    }

    #[Test]
    public function the_link_points_at_the_front_and_carries_a_signature(): void
    {
        $this->actAsStaff();
        $order = $this->order();

        $response = $this->postJson("/api/v1/orders/{$order->getKey()}/invoice-link", [], [
            'X-Tenant-ID' => $this->tenant->slug,
        ])->assertOk();

        $this->assertStringStartsWith(
            "https://app.restauraerp.test/invoice/{$order->getKey()}?",
            $response->json('url'),
        );
        $this->assertStringContainsString('signature=', $response->json('url'));
    }

    #[Test]
    public function a_customer_with_no_account_can_open_it(): void
    {
        [$order, $query] = $this->sharedOrder();

        // No token, and no X-Tenant-ID: a customer opening a WhatsApp link has
        // neither, and the endpoint has to work without them.
        $this->getJson("/api/v1/orders/{$order->getKey()}/invoice?{$query}")
            ->assertOk()
            ->assertJsonPath('order.id', $order->getKey())
            ->assertJsonStructure(['restaurant' => ['name', 'currency'], 'order' => ['id', 'total', 'items']]);
    }

    #[Test]
    public function the_invoice_is_headed_with_the_restaurants_own_name(): void
    {
        // Provisioning already writes a site_name, so this updates rather than
        // inserts - the key is unique per tenant.
        $this->inTenant(fn () => WebsiteSetting::updateOrCreate(
            ['key' => 'site_name'],
            ['value' => 'Bangla Bistro'],
        ));

        [$order, $query] = $this->sharedOrder();

        $this->getJson("/api/v1/orders/{$order->getKey()}/invoice?{$query}")
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'Bangla Bistro');
    }

    #[Test]
    public function the_signature_does_not_open_a_different_order(): void
    {
        [, $query] = $this->sharedOrder();
        $someoneElses = $this->order();

        $this->getJson("/api/v1/orders/{$someoneElses->getKey()}/invoice?{$query}")
            ->assertForbidden();
    }

    #[Test]
    public function an_unsigned_request_is_refused(): void
    {
        [$order] = $this->sharedOrder();

        $this->getJson("/api/v1/orders/{$order->getKey()}/invoice")
            ->assertForbidden();
    }

    #[Test]
    public function a_link_stops_working_once_it_expires(): void
    {
        [$order, $query] = $this->sharedOrder();

        $this->travelTo(Carbon::now()->addDays(31));

        $this->getJson("/api/v1/orders/{$order->getKey()}/invoice?{$query}")
            ->assertForbidden();
    }

    /**
     * wa.me will not resolve a national-form number: `01711000020` opens
     * nothing. Rows written before phone canonicalisation still hold one, so
     * the API converts rather than trusting what is stored.
     */
    #[Test]
    public function the_customers_number_comes_back_in_the_form_whatsapp_accepts(): void
    {
        $this->actAsStaff();

        $customer = $this->inTenant(fn () => Customer::create([
            'name' => 'Rakib Mia',
            'phone' => '01711000020',
        ]));

        $order = $this->order($customer);

        $this->postJson("/api/v1/orders/{$order->getKey()}/invoice-link", [], [
            'X-Tenant-ID' => $this->tenant->slug,
        ])->assertOk()->assertJsonPath('customer_phone', '8801711000020');
    }

    #[Test]
    public function a_walk_in_with_no_number_shares_without_one(): void
    {
        $this->actAsStaff();
        $order = $this->order();

        $this->postJson("/api/v1/orders/{$order->getKey()}/invoice-link", [], [
            'X-Tenant-ID' => $this->tenant->slug,
        ])->assertOk()->assertJsonPath('customer_phone', null);
    }

    #[Test]
    public function minting_a_link_requires_a_login(): void
    {
        $this->actAsStaff();
        $order = $this->order();

        // Creating the authority to read an invoice is the restaurant's act.
        app('auth')->forgetGuards();

        $this->postJson("/api/v1/orders/{$order->getKey()}/invoice-link", [], [
            'X-Tenant-ID' => $this->tenant->slug,
        ])->assertUnauthorized();
    }
}
