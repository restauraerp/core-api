<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The endpoint that hands out the demo restaurant's password.
 *
 * It answers unauthenticated and by design - the credentials are printed on
 * the marketing site, and the front asks here rather than baking them into a
 * bundle shipped to every visitor. The entire safeguard is that
 * `app.demo_mode` is false everywhere except the demo box, and nothing tested
 * that. A default flipped by accident would publish a working login on every
 * customer install, silently and with no failing test to say so.
 */
class DemoConfigTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_customer_install_does_not_admit_the_endpoint_exists(): void
    {
        config(['app.demo_mode' => false]);

        // 404 rather than 403: a disabled demo should not confirm there is
        // something here to authenticate into.
        $this->getJson('/api/v1/demo-config')->assertNotFound();
    }

    #[Test]
    public function the_demo_box_hands_out_the_credentials(): void
    {
        config([
            'app.demo_mode' => true,
            'app.demo_tenant_slug' => 'bangla-bistro',
            'app.demo_username' => 'demo@banglabistro.com.bd',
            'app.demo_password' => 'demo',
        ]);

        $this->getJson('/api/v1/demo-config')
            ->assertOk()
            ->assertExactJson([
                // The restaurant code, which the login form needs alongside the
                // credentials because emails are unique per tenant, not
                // platform-wide.
                'tenant' => 'bangla-bistro',
                'email' => 'demo@banglabistro.com.bd',
                'password' => 'demo',
            ]);
    }

    /**
     * Reachable without naming a tenant, unlike everything else in the API.
     * Which tenant the demo lives in is exactly what the call answers, so
     * ResolveTenant is skipped for it - and that skip has to keep working.
     */
    #[Test]
    public function it_does_not_require_a_tenant_header(): void
    {
        config(['app.demo_mode' => true]);

        $this->getJson('/api/v1/demo-config')->assertOk();
    }
}
