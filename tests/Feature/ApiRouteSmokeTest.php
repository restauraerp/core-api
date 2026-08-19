<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every collection endpoint answers something, and nothing answers 500.
 *
 * This replaces eleven generated *ApiTest classes that each asked the same
 * question of an endpoint that never existed. tools/generate_tests.php built
 * a URL from the controller's name - OrderItemController became
 * `/api/v1/order-items` - but those resources are registered nested and
 * `->shallow()`, so only `orders/{order}/items` and `/order-items/{id}` are
 * real and the collection never was. Twenty-two tests asserted a 404 was
 * wrong, when the 404 was the correct answer to a made-up question.
 *
 * The routes are read from the router rather than listed here, which is the
 * point: a guessed list is what went stale last time. Adding a resource puts
 * it under test with no edit here, and deleting one cannot leave an assertion
 * behind pointing at nothing.
 *
 * What is asserted is deliberately weak - not 404, not 5xx. This is a smoke
 * test and says so: it catches a route that was renamed out from under its
 * callers, or one that throws before it can refuse. Whether the body is
 * *correct* is a question for the test that owns that feature.
 */
class ApiRouteSmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Collection routes needing more than a signed-in user to reach.
     *
     * Each is excluded for a stated reason, because an unexplained skip list is
     * how a route quietly stops being tested.
     *
     * @var list<string>
     */
    private const NOT_A_TENANT_QUESTION = [
        // Platform administration, gated on is_platform_admin - a restaurant
        // owner is not one and 403 here is the whole point.
        'api/v1/platform/plans',
        'api/v1/platform/stats',
        'api/v1/platform/tenants',
        // Streams a CSV download, not a JSON collection.
        'api/v1/customers-export',
        // 404s deliberately whenever demo mode is off, which is every
        // environment except the demo box - including this one. That the 404
        // is correct rather than a broken route is asserted by DemoConfigTest.
        'api/v1/demo-config',
    ];

    /**
     * @return list<string>
     */
    private function collectionRoutes(): array
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1')) {
                continue;
            }

            // Collections only. A member route needs an id that exists, which
            // is a fixture question this test deliberately does not ask.
            if (str_contains($uri, '{')) {
                continue;
            }

            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (in_array($uri, self::NOT_A_TENANT_QUESTION, true)) {
                continue;
            }

            $uris[$uri] = true;
        }

        return array_keys($uris);
    }

    #[Test]
    public function every_collection_endpoint_answers_a_signed_in_user(): void
    {
        // Enterprise: entitlement refusals are a different test's subject
        // (PlanEntitlementTest), and a 403 here would only be noise.
        $tenant = Tenant::factory()->create(['plan' => 'enterprise']);
        $user = User::factory()->create(['tenant_id' => $tenant->getKey()]);

        Sanctum::actingAs($user);

        $routes = $this->collectionRoutes();

        // If this ever drops to nothing the test would pass by testing nothing.
        $this->assertGreaterThan(30, count($routes), 'Expected the API to register far more collection routes than this.');

        $broken = [];

        foreach ($routes as $uri) {
            $status = $this->getJson('/'.$uri)->status();

            if ($status === 404 || $status >= 500) {
                $broken[] = $uri.' -> '.$status;
            }
        }

        $this->assertSame([], $broken, "Collection routes that 404'd or threw:\n".implode("\n", $broken));
    }

    /**
     * The other half: without a token and without a header, nothing serves one
     * restaurant's data to a caller who never said which restaurant they meant.
     */
    #[Test]
    public function an_unattributed_request_is_never_served_a_tenants_data(): void
    {
        $served = [];

        foreach ($this->collectionRoutes() as $uri) {
            // Endpoints that answer the same for everybody have no tenant to
            // leak; they are listed by what they are, not skipped silently.
            if (in_array($uri, ['api/v1/location-types', 'api/v1/auth/me'], true)) {
                continue;
            }

            $status = $this->getJson('/'.$uri)->status();

            if ($status === 200) {
                $served[] = $uri;
            }
        }

        $this->assertSame([], $served, "Served data with no tenant named:\n".implode("\n", $served));
    }
}
