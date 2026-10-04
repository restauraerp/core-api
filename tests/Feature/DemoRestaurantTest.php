<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Demo restaurants: marked for testing or sales presentations.
 *
 * They are not customers. They stay out of the subscription lists and the
 * daily report, no trial or subscription runs out on them, and the app shows
 * them no upgrade prompt - but suspending one still switches it off.
 */
class DemoRestaurantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['platform.token' => 'test-secret']);
    }

    private function platform(): self
    {
        return $this->withToken('test-secret');
    }

    /** A trial that ran out yesterday - read-only for a customer. */
    private function lapsedTrial(string $slug, bool $demo = false): Tenant
    {
        return Tenant::factory()->create([
            'slug' => $slug,
            'status' => 'trialing',
            'trial_ends_at' => now()->subDay(),
            'is_demo' => $demo,
        ]);
    }

    #[Test]
    public function demo_restaurants_have_their_own_list_and_are_left_out_of_every_other(): void
    {
        $this->lapsedTrial('real-trial');
        $this->lapsedTrial('sales-demo', demo: true);
        Tenant::factory()->create(['slug' => 'paying', 'status' => 'active']);

        $all = $this->platform()->getJson('/api/v1/platform/tenants')->assertOk();
        $codes = array_column($all->json('tenants'), 'restaurant_code');
        $this->assertContains('real-trial', $codes);
        $this->assertNotContains('sales-demo', $codes);
        $this->assertSame(1, $all->json('counts.demo'));
        $this->assertSame(1, $all->json('counts.trialing'));
        $this->assertSame(count($codes), $all->json('counts.all'));

        $trials = $this->platform()->getJson('/api/v1/platform/tenants?status=trialing')->json('tenants');
        $this->assertSame(['real-trial'], array_column($trials, 'restaurant_code'));

        $demos = $this->platform()->getJson('/api/v1/platform/tenants?status=demo')->json('tenants');
        $this->assertSame(['sales-demo'], array_column($demos, 'restaurant_code'));
        $this->assertTrue($demos[0]['is_demo']);
    }

    #[Test]
    public function marking_a_restaurant_as_a_demo_lifts_its_billing_and_unmarking_restores_it(): void
    {
        $tenant = $this->lapsedTrial('pitch');
        $this->assertSame(Subscription::READ_ONLY, Subscription::for($tenant)['state']);

        $this->platform()->putJson('/api/v1/platform/tenants/pitch/demo', ['is_demo' => true])
            ->assertOk()
            ->assertJsonPath('tenant.is_demo', true);

        // The cached answer is dropped, so this is the new state, not the old.
        $this->assertSame(Subscription::FULL, Subscription::for($tenant)['state']);
        $this->assertNull(Subscription::for($tenant)['expires_at']);

        $this->platform()->putJson('/api/v1/platform/tenants/pitch/demo', ['is_demo' => false])
            ->assertOk()
            ->assertJsonPath('tenant.is_demo', false);
        $this->assertSame(Subscription::READ_ONLY, Subscription::for($tenant)['state'], 'back to its own lapsed trial');

        $this->platform()->putJson('/api/v1/platform/tenants/pitch/demo', [])->assertUnprocessable();
        $this->platform()->putJson('/api/v1/platform/tenants/nobody/demo', ['is_demo' => true])->assertNotFound();
        $this->flushHeaders()->putJson('/api/v1/platform/tenants/pitch/demo', ['is_demo' => true])->assertUnauthorized();
    }

    #[Test]
    public function a_suspended_demo_is_still_switched_off(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'suspended', 'is_demo' => true]);

        $this->assertSame(Subscription::READ_ONLY, Subscription::resolve($tenant)['state']);
    }

    #[Test]
    public function the_nightly_expiry_leaves_demo_restaurants_alone(): void
    {
        $demo = $this->lapsedTrial('demo-one', demo: true);
        $real = $this->lapsedTrial('real-one');

        $this->artisan('tenants:expire')->assertSuccessful();

        $this->assertSame('trialing', $demo->fresh()->status);
        $this->assertSame('suspended', $real->fresh()->status);
    }

    #[Test]
    public function the_daily_report_does_not_count_demo_restaurants_as_business(): void
    {
        $this->lapsedTrial('real-trial');
        $this->lapsedTrial('demo-trial', demo: true);
        Tenant::factory()->create(['status' => 'active', 'is_demo' => true]);

        $this->platform()->getJson('/api/v1/platform/stats?timezone=UTC')
            ->assertOk()
            ->assertJsonPath('on_trial', 1)
            ->assertJsonPath('active_subscribers', 0)
            ->assertJsonPath('trials_expired_unconverted', 1)
            ->assertJsonPath('restaurants_total', 1)
            ->assertJsonPath('demo_restaurants', 2);
    }

    #[Test]
    public function the_app_is_told_it_is_a_demo_and_given_no_trial_countdown(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'showroom', 'status' => 'trialing', 'trial_ends_at' => now()->addDays(3), 'is_demo' => true,
        ]);
        Sanctum::actingAs(User::factory()->forTenant($tenant)->create());

        $this->withHeader('X-Tenant-ID', 'showroom')->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('subscription.demo_restaurant', true)
            ->assertJsonPath('subscription.state', Subscription::FULL)
            ->assertJsonPath('subscription.trial_days_remaining', null);
    }
}
