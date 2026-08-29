<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
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

    /**
     * Replaces the fake set up above, rather than adding to it.
     *
     * Stubs are matched in the order they were registered, so a second
     * `Http::fake(['*' => ...])` never gets a look in - the catch-all from setUp
     * answers first and the test silently asserts against the wrong response.
     */
    private function websiteAnswers(mixed $response): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => $response]);
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

    public function test_a_signed_in_trial_user_is_attributed_to_their_restaurant(): void
    {
        // The case that was broken in three places at once, and invisibly: this
        // route sits outside `auth:sanctum`, so nothing resolved the user; it
        // skips ResolveTenant, so TenantScope was fail-closed and Sanctum could
        // not find the token's owner either; and the code asked the tenant for a
        // `restaurant_code` column that does not exist. Every trial and video
        // reading was accepted and then dropped for want of anybody to attribute
        // it to, so nobody ever reached the walkthrough-completed rungs.
        $tenant = Tenant::factory()->create(['slug' => 'spice-garden']);
        $user = User::factory()->create(['tenant_id' => $tenant->getKey()]);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/v1/walkthrough/progress', ['kind' => 'trial', 'percent' => 100])
            ->assertOk();

        Http::assertSent(fn ($request) => $request['tenant_code'] === 'spice-garden'
            && $request['kind'] === 'trial');
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

    /*
    |--------------------------------------------------------------------------
    | Reading it back
    |--------------------------------------------------------------------------
    |
    | The same relay, the other way round, so a half-finished walkthrough can be
    | resumed. It carries the same obligation the reporting half does: whichever
    | restaurant is asking comes from the session, never from the request.
    */

    public function test_a_resume_position_is_relayed_back_to_the_caller(): void
    {
        $this->websiteAnswers(Http::response([
            'found' => true,
            'kind' => 'demo',
            'percent' => 66,
            'last_key' => 'stock-falling',
            'keys_seen' => ['todays-takings', 'live-orders'],
            'completed' => false,
        ], 200));

        $this->getJson('/api/v1/walkthrough/progress?kind=demo&ref=opaque-token')
            ->assertOk()
            ->assertJson(['found' => true, 'percent' => 66, 'last_key' => 'stock-falling']);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://restauraerp.test/api/marketing/progress')
            && $request['ref'] === 'opaque-token');
    }

    public function test_a_signed_in_user_reads_their_own_restaurant_position(): void
    {
        $this->websiteAnswers(Http::response(['found' => true, 'percent' => 33, 'last_key' => 'set-up-tables'], 200));

        $tenant = Tenant::factory()->create(['slug' => 'spice-garden']);
        $user = User::factory()->create(['tenant_id' => $tenant->getKey()]);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/v1/walkthrough/progress?kind=trial')
            ->assertOk();

        // Not from the request. A client-supplied code here would let one
        // restaurant read another's progress out of this endpoint.
        Http::assertSent(fn ($request) => $request['tenant_code'] === 'spice-garden');
    }

    public function test_an_unreachable_website_answers_nothing_found_rather_than_failing(): void
    {
        // Somebody is opening the product, not waiting on this. A tour that
        // refuses to start because a lookup timed out is worse than one that
        // starts at the beginning.
        $this->websiteAnswers(Http::response('gateway gone', 502));

        $this->getJson('/api/v1/walkthrough/progress?kind=demo&ref=opaque-token')
            ->assertOk()
            ->assertJson(['found' => false, 'percent' => 0, 'last_key' => null, 'completed' => false]);
    }

    public function test_an_unconfigured_platform_link_answers_nothing_found(): void
    {
        Config::set('platform.token', '');

        $this->getJson('/api/v1/walkthrough/progress?kind=demo')
            ->assertOk()
            ->assertJson(['found' => false]);

        Http::assertNothingSent();
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $this->getJson('/api/v1/walkthrough/progress?kind=holiday')->assertStatus(422);
    }
}
