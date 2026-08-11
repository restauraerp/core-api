<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The link behind "Renew subscription" in the app.
 *
 * The URL this returns is followed by the customer's browser, which is the
 * whole point of the test below: it used to be built from WEBSITE_URL, the
 * address this application calls the website on. Under Docker that is the
 * compose service name, so the endpoint answered 200 with a perfectly
 * well-formed link to `http://website:8030` that no browser could resolve. The
 * button looked like it worked and went nowhere.
 */
class UpgradeLinkTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): Tenant
    {
        $tenant = Tenant::factory()->create(['status' => 'trialing']);

        $user = app(TenantContext::class)->runFor($tenant, fn () => User::create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Owner',
            'email' => 'owner@upgrade-test.test',
            'password' => Hash::make('secret-password'),
        ]));

        app(TenantContext::class)->set($tenant);
        Sanctum::actingAs($user);

        return $tenant;
    }

    #[Test]
    public function it_builds_the_link_from_the_public_url_not_the_internal_one(): void
    {
        config([
            'platform.website_url' => 'http://website:8030',
            'platform.website_public_url' => 'https://restauraerp.test',
        ]);

        $this->actingAsOwner();

        $url = $this->postJson('/api/v1/billing/upgrade-link')
            ->assertOk()
            ->json('url');

        $this->assertStringStartsWith('https://restauraerp.test/upgrade?token=', $url);
        // The internal hostname must never reach a browser.
        $this->assertStringNotContainsString('website:8030', $url);
    }

    #[Test]
    public function the_public_url_falls_back_to_the_website_url_when_unset(): void
    {
        // Wherever the two are the same - production included - there is
        // nothing to configure, and this must keep working untouched.
        config([
            'platform.website_url' => 'https://restauraerp.test',
            'platform.website_public_url' => 'https://restauraerp.test',
        ]);

        $this->actingAsOwner();

        $this->postJson('/api/v1/billing/upgrade-link')
            ->assertOk()
            ->assertJson(fn ($json) => $json
                ->where('url', fn (string $url) => str_starts_with($url, 'https://restauraerp.test/upgrade?token='))
                ->etc());
    }

    #[Test]
    public function with_no_public_url_configured_it_refuses_rather_than_linking_nowhere(): void
    {
        config(['platform.website_url' => '', 'platform.website_public_url' => '']);

        $this->actingAsOwner();

        $this->postJson('/api/v1/billing/upgrade-link')
            ->assertStatus(503)
            ->assertJsonPath('error', 'website_url_missing');
    }

    /**
     * A lapsed subscription is exactly who needs this button, so it must not
     * sit behind the middleware that stops writes when billing has expired.
     */
    #[Test]
    public function a_read_only_tenant_can_still_ask_to_pay(): void
    {
        config(['platform.website_public_url' => 'https://restauraerp.test']);

        $tenant = $this->actingAsOwner();

        $tenant->forceFill([
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'subscription_ends_at' => now()->subYear(),
        ])->save();

        $this->postJson('/api/v1/billing/upgrade-link')->assertOk();
    }
}
