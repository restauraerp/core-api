<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Site-builder access tokens, for the RestoraERP MCP server: they build the
 * website and reach nothing else.
 */
class SiteAccessTokenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Spice Garden', 'slug' => 'spice', 'plan' => 'growth', 'status' => 'active',
        ]);
        $this->owner = app(TenantContext::class)->runFor($this->tenant, function () {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $user->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

            return $user;
        });
    }

    /** A site-builder token, made the way the builder makes one. */
    private function siteToken(): string
    {
        $login = $this->owner->createToken('auth_token')->plainTextToken;

        $token = $this->withToken($login)
            ->postJson('/api/v1/site-builder/access-tokens', ['name' => 'Claude Code', 'expires_in_days' => 30])
            ->assertCreated()
            ->json('token');

        // Each request below authenticates afresh with the header it is given.
        $this->app['auth']->forgetGuards();

        return $token;
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    #[Test]
    public function a_site_builder_token_builds_the_website(): void
    {
        $token = $this->siteToken();

        $home = $this->as($token)->getJson('/api/v1/site-pages')->assertOk()->json('pages.0.id');
        $this->as($token)->putJson("/api/v1/site-pages/{$home}", ['sections' => [['type' => 'text', 'props' => ['heading' => 'Hi']]]])->assertOk();
        $this->as($token)->getJson('/api/v1/site-builder/components')->assertOk();
        $this->as($token)->postJson('/api/v1/site-builder/publish')->assertCreated();
        $this->as($token)->getJson('/api/v1/order/spice')->assertOk();
        $this->as($token)->getJson('/api/v1/google-reviews')->assertOk();
    }

    #[Test]
    public function it_reaches_nothing_else(): void
    {
        $token = $this->siteToken();

        $this->as($token)->getJson('/api/v1/orders')->assertForbidden();
        $this->as($token)->getJson('/api/v1/users')->assertForbidden();
        $this->as($token)->postJson('/api/v1/google-reviews', ['author_name' => 'x', 'rating' => 5])->assertForbidden();
        // Not even its own kind: a token cannot make or list tokens.
        $this->as($token)->getJson('/api/v1/site-builder/access-tokens')->assertForbidden();
        $this->as($token)->postJson('/api/v1/site-builder/access-tokens', ['name' => 'more'])->assertForbidden();
    }

    #[Test]
    public function a_login_token_is_unaffected(): void
    {
        $login = $this->owner->createToken('auth_token')->plainTextToken;

        $this->as($login)->getJson('/api/v1/orders')->assertOk();
    }

    #[Test]
    public function tokens_are_listed_without_their_secret_and_can_be_revoked(): void
    {
        $token = $this->siteToken();
        $login = $this->owner->createToken('auth_token')->plainTextToken;

        $list = $this->as($login)->getJson('/api/v1/site-builder/access-tokens')
            ->assertOk()
            ->assertJsonCount(1, 'tokens')
            ->assertJsonPath('tokens.0.name', 'Claude Code');
        $this->assertStringNotContainsString(explode('|', $token)[1], $list->getContent());

        $this->as($login)->deleteJson('/api/v1/site-builder/access-tokens/'.$list->json('tokens.0.id'))->assertNoContent();
        $this->as($token)->getJson('/api/v1/site-pages')->assertUnauthorized();
    }

    #[Test]
    public function an_expired_token_stops_working(): void
    {
        $token = $this->siteToken();
        $this->travel(31)->days();

        $this->as($token)->getJson('/api/v1/site-pages')->assertUnauthorized();
    }

    #[Test]
    public function staff_who_cannot_edit_the_website_cannot_make_one(): void
    {
        $chef = app(TenantContext::class)->runFor($this->tenant, function () {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $user->assignRole('chef');

            return $user;
        });

        $this->as($chef->createToken('auth_token')->plainTextToken)
            ->postJson('/api/v1/site-builder/access-tokens', ['name' => 'x'])
            ->assertForbidden();
    }
}
