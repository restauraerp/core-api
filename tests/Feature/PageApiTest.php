<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `pages` is readable without a token - it is what the public
 * storefront renders itself from.
 *
 * Unauthenticated does not mean unattributed, though. With no token there is
 * nothing for ResolveTenant to read the restaurant off, so the caller has to
 * name it, and a request that names nobody is answered 400 rather than served
 * an arbitrary tenant's data. The generated version of this test omitted the
 * header and read that 400 as a failure; it is the contract.
 */
class PageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_index_is_public_to_a_caller_that_names_its_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $this->getJson('/api/v1/pages', ['X-Tenant-ID' => $tenant->slug])
            ->assertOk();
    }

    public function test_pages_index_is_refused_when_no_tenant_can_be_resolved(): void
    {
        $this->getJson('/api/v1/pages')
            ->assertStatus(400);
    }

    public function test_pages_index_returns_200_for_an_authenticated_user(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->getKey()]);

        Sanctum::actingAs($user);

        // No header this time: the token already says which restaurant.
        $this->getJson('/api/v1/pages')->assertOk();
    }
}
