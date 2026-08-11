<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Billing state, counted, for the marketing site's daily report.
 *
 * The awkward parts are the day boundary and the grace window. "Today" has to
 * mean the reader's day in Dhaka, not this server's UTC one, and grace depends
 * on the billing cycle - so a monthly and a yearly subscription that lapsed on
 * the same day are not both still in it.
 */
class PlatformStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['platform.token' => 'test-secret']);

        // Mid-afternoon in Dhaka, still the previous day in UTC - which is what
        // makes the timezone handling visible rather than coincidental.
        Carbon::setTestNow('2026-08-10 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function stats(array $query = []): TestResponse
    {
        return $this->withToken(config('platform.token'))
            ->getJson('/api/v1/platform/stats?'.http_build_query($query + ['timezone' => 'Asia/Dhaka']));
    }

    /**
     * Written in Dhaka time deliberately.
     *
     * A bare '2026-08-10 20:00:00' is stored as UTC, which is already 2am on
     * the 11th in Dhaka - so a fixture that looks like "today" is tomorrow, and
     * the test would pass or fail for reasons that have nothing to do with the
     * code. Stating the timezone is the whole point of these two.
     */
    private function dhaka(string $when): Carbon
    {
        // Converted to the app timezone before it is stored. Laravel writes
        // whatever timezone the Carbon happens to carry and reads it back as
        // app-timezone, so a Dhaka-flavoured instance saved raw comes back
        // meaning something else entirely. Real code never hits this because
        // its dates come from now(), which is already in app time.
        return Carbon::parse($when, 'Asia/Dhaka')->setTimezone(config('app.timezone'));
    }

    #[Test]
    public function it_counts_trials_ending_today(): void
    {
        Tenant::factory()->create(['status' => 'trialing', 'trial_ends_at' => $this->dhaka('2026-08-10 20:00')]);
        Tenant::factory()->create(['status' => 'trialing', 'trial_ends_at' => $this->dhaka('2026-08-10 00:30')]);
        Tenant::factory()->create(['status' => 'trialing', 'trial_ends_at' => $this->dhaka('2026-08-11 10:00')]);
        Tenant::factory()->create(['status' => 'trialing', 'trial_ends_at' => $this->dhaka('2026-08-09 23:30')]);

        // Both of the 10th, neither of the 9th or 11th - including the two that
        // sit within half an hour of a Dhaka midnight.
        $this->stats()->assertOk()->assertJsonPath('trials_ending_today', 2);
    }

    #[Test]
    public function it_counts_subscriptions_ending_today(): void
    {
        Tenant::factory()->create(['status' => 'active', 'subscription_ends_at' => $this->dhaka('2026-08-10 20:00')]);
        Tenant::factory()->create(['status' => 'active', 'subscription_ends_at' => $this->dhaka('2026-08-15 20:00')]);

        $this->stats()->assertOk()->assertJsonPath('subscriptions_ending_today', 1);
    }

    /**
     * The reason the timezone is a parameter at all: the same instant belongs
     * to different days depending on who is asking.
     */
    #[Test]
    public function the_same_data_counts_differently_in_another_timezone(): void
    {
        // 1am on the 11th in Dhaka, still the 10th in UTC.
        Tenant::factory()->create(['status' => 'trialing', 'trial_ends_at' => $this->dhaka('2026-08-11 01:00')]);

        $this->stats()->assertOk()->assertJsonPath('trials_ending_today', 0);

        $this->withToken(config('platform.token'))
            ->getJson('/api/v1/platform/stats?timezone=UTC')
            ->assertOk()
            ->assertJsonPath('trials_ending_today', 1);
    }

    #[Test]
    public function it_counts_payments_falling_due_soon(): void
    {
        Tenant::factory()->create(['status' => 'active', 'subscription_ends_at' => now()->addDays(3)]);
        Tenant::factory()->create(['status' => 'active', 'subscription_ends_at' => now()->addDays(6)]);
        Tenant::factory()->create(['status' => 'active', 'subscription_ends_at' => now()->addDays(20)]);
        // Already lapsed: overdue, not falling due.
        Tenant::factory()->create(['status' => 'active', 'subscription_ends_at' => now()->subDay()]);

        $this->stats()->assertOk()->assertJsonPath('payments_due_soon', 2);
    }

    #[Test]
    public function the_due_window_can_be_widened(): void
    {
        Tenant::factory()->create(['status' => 'active', 'subscription_ends_at' => now()->addDays(20)]);

        $this->stats(['due_within_days' => 30])->assertOk()->assertJsonPath('payments_due_soon', 1);
    }

    /**
     * Grace depends on the cycle, which is why it is counted in PHP rather than
     * with a CASE expression nobody would keep in step.
     */
    #[Test]
    public function grace_is_measured_against_the_billing_cycle(): void
    {
        // One day past its end: inside any grace period.
        Tenant::factory()->create([
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'subscription_ends_at' => now()->subDay(),
        ]);

        // A year past: out of grace on any cycle.
        Tenant::factory()->create([
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'subscription_ends_at' => now()->subYear(),
        ]);

        // Still running - not in grace, just paid up.
        Tenant::factory()->create([
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'subscription_ends_at' => now()->addMonth(),
        ]);

        $this->stats()->assertOk()->assertJsonPath('in_grace', 1);
    }

    #[Test]
    public function it_counts_expired_trials_nobody_converted(): void
    {
        Tenant::factory()->create(['status' => 'trialing', 'trial_ends_at' => now()->subWeek()]);
        Tenant::factory()->create(['status' => 'trialing', 'trial_ends_at' => now()->addWeek()]);
        // Converted: no longer a trial at all.
        Tenant::factory()->create(['status' => 'active', 'trial_ends_at' => now()->subWeek()]);

        $this->stats()->assertOk()->assertJsonPath('trials_expired_unconverted', 1);
    }

    #[Test]
    public function it_reports_the_standing_totals(): void
    {
        Tenant::factory()->count(3)->create(['status' => 'active']);
        Tenant::factory()->count(2)->create(['status' => 'trialing']);
        Tenant::factory()->create(['status' => 'suspended']);

        $this->stats()->assertOk()
            ->assertJsonPath('active_subscribers', 3)
            ->assertJsonPath('on_trial', 2)
            ->assertJsonPath('suspended', 1)
            ->assertJsonPath('restaurants_total', 6);
    }

    /**
     * The counts are cross-tenant, and this route runs outside tenant
     * resolution - so without unscoping every one of them comes back zero.
     */
    #[Test]
    public function the_counts_are_not_silently_scoped_to_nothing(): void
    {
        Tenant::factory()->count(4)->create(['status' => 'active']);

        $this->stats()->assertOk()->assertJsonPath('restaurants_total', 4);
    }

    #[Test]
    public function a_caller_without_the_platform_token_is_refused(): void
    {
        $this->getJson('/api/v1/platform/stats')->assertStatus(401);
    }

    #[Test]
    public function a_nonsense_timezone_is_refused(): void
    {
        $this->withToken(config('platform.token'))
            ->getJson('/api/v1/platform/stats?timezone=Mars/Olympus')
            ->assertStatus(422);
    }
}
