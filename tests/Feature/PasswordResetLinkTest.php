<?php

namespace Tests\Feature;

use App\Models\OneTimeLoginToken;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "I have forgotten my password", and the admin's version of the same thing.
 *
 * Two properties matter more than the happy path. The public route must not
 * reveal whether an address has an account - it takes no authentication, so
 * anything that distinguishes "no such account" from "sent" is a way to test
 * addresses one at a time. And the demo account must never be resettable: its
 * credentials are published on the marketing site, so a reset would let any
 * passer-by lock every other visitor out of the demo.
 */
class PasswordResetLinkTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['status' => 'sent'])]);

        config([
            'platform.website_url' => 'https://website.test',
            'platform.app_url' => 'https://app.test',
            'platform.token' => 'test-secret',
            'app.demo_mode' => true,
            'app.demo_tenant_slug' => 'demo-restaurant',
            'app.demo_username' => 'admin@demo.com',
        ]);

        $this->tenant = Tenant::factory()->create(['slug' => 'spice-garden', 'contact_email' => 'rafiq@spice.test']);
        $this->owner = $this->userFor($this->tenant, 'rafiq@spice.test');
    }

    private function userFor(Tenant $tenant, string $email): User
    {
        return app(TenantContext::class)->runFor($tenant, fn () => User::create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Rafiq',
            'email' => $email,
            'password' => Hash::make('secret-password'),
        ]));
    }

    private function forgot(array $payload): TestResponse
    {
        return $this->postJson('/api/v1/auth/password/forgot', $payload);
    }

    private function resetEmailsSent(): int
    {
        $count = 0;

        Http::recorded(function ($request) use (&$count) {
            if (str_contains($request->url(), 'notifications/password-reset')) {
                $count++;
            }
        });

        return $count;
    }

    #[Test]
    public function it_emails_a_link_to_a_real_account(): void
    {
        $this->forgot(['email' => 'rafiq@spice.test'])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        $this->assertSame(1, $this->resetEmailsSent());
        $this->assertDatabaseCount('one_time_login_tokens', 1);
    }

    #[Test]
    public function the_link_forces_a_new_password_after_it_is_used(): void
    {
        $this->forgot(['email' => 'rafiq@spice.test'])->assertOk();

        // Otherwise redeeming it is a plain login, and somebody who came here
        // because they had forgotten their password still would not have one.
        $this->assertTrue((bool) $this->owner->fresh()->must_set_password);
    }

    #[Test]
    public function an_unknown_address_gets_the_same_answer_as_a_real_one(): void
    {
        $real = $this->forgot(['email' => 'rafiq@spice.test']);
        $fake = $this->forgot(['email' => 'nobody@nowhere.test']);

        // Byte for byte: any difference at all is an oracle for whether an
        // address has an account here.
        $this->assertSame($real->status(), $fake->status());
        $this->assertSame($real->json(), $fake->json());
        $this->assertSame(1, $this->resetEmailsSent());
    }

    #[Test]
    public function the_demo_account_is_never_reset(): void
    {
        $demo = Tenant::factory()->create(['slug' => 'demo-restaurant']);
        $this->userFor($demo, 'admin@demo.com');

        $this->forgot(['email' => 'admin@demo.com'])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        // Same answer as everyone else, but nothing sent and no token minted.
        $this->assertSame(0, $this->resetEmailsSent());
        $this->assertDatabaseCount('one_time_login_tokens', 0);
    }

    #[Test]
    public function the_demo_address_is_refused_even_in_another_restaurant(): void
    {
        $other = Tenant::factory()->create(['slug' => 'not-the-demo']);
        $this->userFor($other, 'admin@demo.com');

        $this->forgot(['email' => 'admin@demo.com', 'restaurant_code' => 'not-the-demo'])->assertOk();

        $this->assertSame(0, $this->resetEmailsSent());
    }

    #[Test]
    public function an_address_owning_two_restaurants_needs_the_code(): void
    {
        $second = Tenant::factory()->create(['slug' => 'second-branch']);
        $this->userFor($second, 'rafiq@spice.test');

        // Ambiguous: sending a link for the wrong restaurant is worse than
        // sending none, so it refuses rather than guessing.
        $this->forgot(['email' => 'rafiq@spice.test'])->assertOk();
        $this->assertSame(0, $this->resetEmailsSent());

        $this->forgot(['email' => 'rafiq@spice.test', 'restaurant_code' => 'second-branch'])->assertOk();
        $this->assertSame(1, $this->resetEmailsSent());
    }

    #[Test]
    public function a_malformed_address_is_refused(): void
    {
        $this->forgot(['email' => 'not-an-address'])->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | The admin's version
    |--------------------------------------------------------------------------
    */

    private function adminSend(string $slug): TestResponse
    {
        return $this->withToken(config('platform.token'))
            ->postJson("/api/v1/platform/tenants/{$slug}/password-reset");
    }

    #[Test]
    public function an_admin_can_send_a_reset_to_the_owner(): void
    {
        $this->adminSend('spice-garden')
            ->assertOk()
            ->assertJsonPath('sent', true)
            ->assertJsonPath('email', 'rafiq@spice.test');

        $this->assertSame(1, $this->resetEmailsSent());
    }

    #[Test]
    public function an_admin_is_told_when_there_is_nobody_to_send_to(): void
    {
        $orphan = Tenant::factory()->create(['slug' => 'no-owner', 'contact_email' => 'ghost@nowhere.test']);

        $this->adminSend($orphan->slug)
            ->assertStatus(422)
            ->assertJsonPath('error', 'owner_missing');
    }

    #[Test]
    public function an_admin_cannot_reset_the_demo_either(): void
    {
        $demo = Tenant::factory()->create(['slug' => 'demo-restaurant', 'contact_email' => 'admin@demo.com']);
        $this->userFor($demo, 'admin@demo.com');

        // Answered honestly here - the caller is our own admin panel, not an
        // anonymous stranger, so there is nothing to hide from it.
        $this->adminSend('demo-restaurant')
            ->assertStatus(422)
            ->assertJsonPath('error', 'account_not_resettable');

        $this->assertSame(0, $this->resetEmailsSent());
    }

    #[Test]
    public function a_caller_without_the_platform_token_is_refused(): void
    {
        $this->postJson('/api/v1/platform/tenants/spice-garden/password-reset')
            ->assertStatus(401);

        $this->assertSame(0, $this->resetEmailsSent());
    }

    #[Test]
    public function issuing_a_new_link_spends_any_earlier_one(): void
    {
        $this->forgot(['email' => 'rafiq@spice.test'])->assertOk();
        $this->forgot(['email' => 'rafiq@spice.test'])->assertOk();

        // Two links exist but only the newest is live: an old link left usable
        // is a credential nobody remembers issuing.
        $this->assertSame(1, OneTimeLoginToken::whereNull('used_at')->count());
    }
}
