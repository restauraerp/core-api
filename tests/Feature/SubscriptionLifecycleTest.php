<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\Subscription;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The billing lifecycle, state by state.
 *
 * The rule these all check: money problems make a restaurant read-only, they do
 * not lock it out. A manager whose invoice is late can still log in, see every
 * order and read every setting - they just cannot save anything new, and they
 * are told why and who to call.
 */
class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function tenant(array $attributes): Tenant
    {
        $tenant = Tenant::factory()->create(array_merge([
            'plan' => 'growth',
            'slug' => 'billing-test',
        ], $attributes));

        Subscription::forget($tenant);

        return $tenant;
    }

    private function actingAsUserOf(Tenant $tenant): User
    {
        $user = app(TenantContext::class)->runFor(
            $tenant,
            fn () => User::factory()->create(['tenant_id' => $tenant->getKey()]),
        );

        Sanctum::actingAs($user);

        return $user;
    }

    private function write(Tenant $tenant)
    {
        return $this->postJson('/api/v1/tags', [
            'name' => 'Probe',
            'slug' => 'probe-'.uniqid(),
        ], ['X-Tenant-ID' => $tenant->slug]);
    }

    private function read(Tenant $tenant)
    {
        return $this->getJson('/api/v1/products', ['X-Tenant-ID' => $tenant->slug]);
    }

    public function test_a_running_trial_can_read_and_write(): void
    {
        $tenant = $this->tenant(['status' => 'trialing', 'trial_ends_at' => now()->addDays(3)]);
        $this->actingAsUserOf($tenant);

        $this->read($tenant)->assertOk();
        $this->write($tenant)->assertCreated();
    }

    public function test_an_expired_trial_goes_read_only_immediately_with_no_grace(): void
    {
        // One second past the end date is enough - a trial has nothing paid to
        // be patient about.
        $tenant = $this->tenant(['status' => 'trialing', 'trial_ends_at' => now()->subSecond()]);
        $this->actingAsUserOf($tenant);

        $this->read($tenant)->assertOk();

        $this->write($tenant)
            ->assertForbidden()
            ->assertJsonPath('error', 'trial_expired')
            ->assertJsonPath('reads_allowed', true)
            ->assertJsonPath('writes_allowed', false);
    }

    public function test_a_monthly_subscription_keeps_full_access_inside_its_seven_day_grace(): void
    {
        $tenant = $this->tenant([
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'subscription_ends_at' => now()->subDays(6),
        ]);
        $this->actingAsUserOf($tenant);

        $this->write($tenant)->assertCreated();
    }

    public function test_a_monthly_subscription_goes_read_only_after_its_grace(): void
    {
        $tenant = $this->tenant([
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'subscription_ends_at' => now()->subDays(8),
        ]);
        $this->actingAsUserOf($tenant);

        $this->read($tenant)->assertOk();
        $this->write($tenant)
            ->assertForbidden()
            ->assertJsonPath('error', 'subscription_expired');
    }

    public function test_a_yearly_subscription_gets_fourteen_days_of_grace(): void
    {
        // Day 9 would already be read-only on monthly.
        $tenant = $this->tenant([
            'status' => 'active',
            'billing_cycle' => 'yearly',
            'subscription_ends_at' => now()->subDays(9),
        ]);
        $this->actingAsUserOf($tenant);

        $this->write($tenant)->assertCreated();
    }

    public function test_a_yearly_subscription_goes_read_only_after_fourteen_days(): void
    {
        $tenant = $this->tenant([
            'status' => 'active',
            'billing_cycle' => 'yearly',
            'subscription_ends_at' => now()->subDays(15),
        ]);
        $this->actingAsUserOf($tenant);

        $this->write($tenant)->assertForbidden();
    }

    public function test_a_suspended_tenant_is_read_only_not_locked_out(): void
    {
        $tenant = $this->tenant(['status' => 'suspended']);
        $this->actingAsUserOf($tenant);

        $this->read($tenant)->assertOk();
        $this->write($tenant)
            ->assertForbidden()
            ->assertJsonPath('error', 'account_suspended');
    }

    public function test_a_cancelled_tenant_is_refused_everything(): void
    {
        $tenant = $this->tenant(['status' => 'cancelled']);
        $this->actingAsUserOf($tenant);

        $this->read($tenant)
            ->assertForbidden()
            ->assertJsonPath('error', 'subscription_cancelled');
        $this->write($tenant)->assertForbidden();
    }

    public function test_the_refusal_explains_itself_and_says_who_to_contact(): void
    {
        config([
            'support.email' => 'billing@example.test',
            'support.phone' => '+8801700000000',
            'support.billing_url' => 'https://example.test/pricing',
        ]);

        $tenant = $this->tenant(['status' => 'trialing', 'trial_ends_at' => now()->subDay()]);
        $this->actingAsUserOf($tenant);

        $response = $this->write($tenant)->assertForbidden();

        $response->assertJsonPath('contact.email', 'billing@example.test');
        $response->assertJsonPath('contact.phone', '+8801700000000');
        $response->assertJsonPath('contact.url', 'https://example.test/pricing');

        // Prose, not a status code: the person reading it is a restaurant
        // manager mid-shift, not a developer.
        $this->assertStringContainsString('trial ended', $response->json('message'));
        $this->assertStringContainsString('still here', $response->json('message'));
    }

    public function test_a_grace_period_warning_rides_along_with_successful_writes(): void
    {
        $tenant = $this->tenant([
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'subscription_ends_at' => now()->subDays(2),
        ]);
        $this->actingAsUserOf($tenant);

        $this->write($tenant)
            ->assertCreated()
            ->assertJsonPath('subscription_warning.error', 'subscription_in_grace');
    }

    public function test_deletes_are_blocked_too(): void
    {
        $tenant = $this->tenant(['status' => 'trialing', 'trial_ends_at' => now()->addDay()]);
        $this->actingAsUserOf($tenant);

        $tagId = $this->write($tenant)->assertCreated()->json('id');

        $tenant->update(['trial_ends_at' => now()->subDay()]);
        Subscription::forget($tenant);

        $this->deleteJson("/api/v1/tags/{$tagId}", [], ['X-Tenant-ID' => $tenant->slug])
            ->assertForbidden();
    }

    public function test_subscribing_restores_writing_immediately(): void
    {
        $tenant = $this->tenant([
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'subscription_ends_at' => now()->subDays(30),
        ]);
        $this->actingAsUserOf($tenant);

        $this->write($tenant)->assertForbidden();

        // No cache flush between these two calls: the command has to invalidate
        // its own entry, or a customer who has just paid keeps seeing the
        // refusal until the TTL runs out.
        $this->artisan('tenants:subscribe', ['tenant' => $tenant->slug, '--monthly' => true])
            ->assertSuccessful();

        $this->write($tenant)->assertCreated();
    }

    public function test_renewing_early_extends_from_the_existing_end_date(): void
    {
        $endsAt = now()->addDays(20);

        $tenant = $this->tenant([
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'subscription_ends_at' => $endsAt,
        ]);

        $this->artisan('tenants:subscribe', ['tenant' => $tenant->slug, '--monthly' => true])
            ->assertSuccessful();

        // A month from the old end date, not from today - paying early must not
        // cost the customer the 20 days they already hold.
        $this->assertSame(
            $endsAt->copy()->addMonth()->toDateString(),
            $tenant->fresh()->subscription_ends_at->toDateString(),
        );
    }

    public function test_subscribing_revives_a_suspended_tenant(): void
    {
        $tenant = $this->tenant(['status' => 'suspended']);

        $this->artisan('tenants:subscribe', ['tenant' => $tenant->slug, '--yearly' => true])
            ->assertSuccessful();

        $tenant->refresh();

        $this->assertSame('active', $tenant->status);
        $this->assertSame('yearly', $tenant->billing_cycle);
        $this->assertSame(Subscription::FULL, $tenant->subscription()['state']);
    }

    public function test_the_expiry_sweep_leaves_tenants_inside_their_grace_alone(): void
    {
        $inGrace = $this->tenant([
            'slug' => 'in-grace',
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'subscription_ends_at' => now()->subDays(3),
        ]);
        $pastGrace = $this->tenant([
            'slug' => 'past-grace',
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'subscription_ends_at' => now()->subDays(10),
        ]);

        $this->artisan('tenants:expire')->assertSuccessful();

        $this->assertSame('active', $inGrace->fresh()->status);
        $this->assertSame('suspended', $pastGrace->fresh()->status);
    }

    public function test_a_new_tenant_gets_a_seven_day_trial(): void
    {
        $this->artisan('tenants:create', ['name' => 'Seven Day', '--slug' => 'seven-day'])
            ->assertSuccessful();

        $tenant = Tenant::where('slug', 'seven-day')->firstOrFail();

        $this->assertSame('trialing', $tenant->status);
        $this->assertSame(7, (int) now()->startOfDay()->diffInDays($tenant->trial_ends_at->startOfDay()));
    }
}
