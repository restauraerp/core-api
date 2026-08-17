<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Relaying how far somebody got to the marketing site.
 *
 * This endpoint holds no records of its own - the website owns the customers and
 * the lifecycle ladder. All that is tested here is the relay itself, and the two
 * things it must never do: block the product when the website is unreachable, and
 * take a restaurant's identity from the request rather than the session.
 *
 * The demo case is the one that matters most and the one easiest to miss. A demo
 * visitor has no tenant, so this route skips tenant resolution entirely - which
 * means nothing here may assume a tenant is in the container.
 */
class WalkthroughProgressRelayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('platform.website_url', 'https://restauraerp.test');
        Config::set('platform.token', 'platform-secret');

        Http::fake(['*' => Http::response(['recorded' => true], 200)]);
    }

    private function report(array $payload = [])
    {
        return $this->postJson('/api/v1/walkthrough/progress', $payload + [
            'kind' => 'demo',
            'percent' => 40,
        ]);
    }

    public function test_a_demo_visitor_with_no_tenant_is_relayed_rather_than_erroring(): void
    {
        // Nothing binds a tenant on this route. Reaching for one directly threw a
        // BindingResolutionException, and because the browser swallows every
        // failure here by design, the tour looked fine while nothing was recorded.
        $this->report(['ref' => 'opaque-token-from-the-website'])
            ->assertOk()
            ->assertJson(['status' => 'accepted']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://restauraerp.test/api/marketing/progress'
                && $request['ref'] === 'opaque-token-from-the-website'
                && $request['tenant_code'] === null;
        });
    }

    public function test_a_tenant_code_in_the_request_is_ignored(): void
    {
        // Otherwise one restaurant could walk another's account up the ladder.
        $this->report(['tenant_code' => 'somebody-elses-restaurant']);

        Http::assertSent(fn ($request) => $request['tenant_code'] === null);
    }

    public function test_the_platform_token_travels_with_the_relay(): void
    {
        $this->report();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer platform-secret'));
    }

    public function test_an_unreachable_website_is_never_the_product_s_problem(): void
    {
        Http::fake(['*' => fn () => throw new \RuntimeException('connection refused')]);

        $this->report()->assertOk();
    }

    public function test_a_refusal_from_the_website_is_swallowed_too(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->report()->assertOk();
    }

    public function test_nothing_is_relayed_without_a_configured_website(): void
    {
        Config::set('platform.website_url', '');

        $this->report()->assertOk()->assertJson(['status' => 'skipped']);

        Http::assertNothingSent();
    }

    public function test_a_reading_that_makes_no_sense_is_rejected(): void
    {
        $this->postJson('/api/v1/walkthrough/progress', ['kind' => 'demo', 'percent' => 140])
            ->assertUnprocessable();

        $this->postJson('/api/v1/walkthrough/progress', ['kind' => 'something-else', 'percent' => 10])
            ->assertUnprocessable();

        Http::assertNothingSent();
    }
}
