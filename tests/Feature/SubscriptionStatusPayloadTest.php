<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Support\Billing\Subscription;
use App\Support\Billing\SubscriptionNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The billing block GET /auth/me carries.
 *
 * A healthy subscription has to describe itself, not only a failing one: the
 * profile screen names the package and the next due date on every visit, and
 * those fields used to exist only on the refusal bodies - so the one screen
 * that should always show them never could.
 */
class SubscriptionStatusPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function statusFor(array $attributes): array
    {
        $tenant = Tenant::factory()->create($attributes);

        Subscription::forget($tenant);

        return SubscriptionNotice::status($tenant->fresh());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_healthy_subscription_names_its_package_and_due_date(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');

        $status = $this->statusFor([
            'status' => 'active',
            'plan' => 'growth',
            'billing_cycle' => 'monthly',
            'subscription_ends_at' => '2026-08-22 00:00:00',
        ]);

        $this->assertSame('full', $status['state']);
        $this->assertSame('growth', $status['plan']);
        $this->assertNotNull($status['plan_name']);
        $this->assertSame('monthly', $status['billing_cycle']);
        $this->assertStringStartsWith('2026-08-22', $status['expires_at']);
        $this->assertSame(12, $status['days_until_expiry']);
    }

    #[Test]
    public function it_says_when_saving_would_actually_stop(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');

        $status = $this->statusFor([
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'subscription_ends_at' => '2026-08-22 00:00:00',
        ]);

        // The due date plus the grace period - sent while still healthy so a
        // client can warn ahead of the deadline rather than after it.
        $this->assertSame(
            Carbon::parse('2026-08-22')->addDays($status['grace_days'])->toDateString(),
            Carbon::parse($status['grace_ends_at'])->toDateString(),
        );
        $this->assertGreaterThan(0, $status['grace_days']);
    }

    #[Test]
    public function a_healthy_subscription_still_offers_somewhere_to_ask_about_billing(): void
    {
        config(['support.email' => 'support@restauraerp.test']);

        $status = $this->statusFor(['status' => 'active', 'subscription_ends_at' => now()->addMonth()]);

        $this->assertSame('support@restauraerp.test', $status['contact']['email']);
    }

    #[Test]
    public function a_trial_reports_its_own_dates_not_a_subscriptions(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');

        $status = $this->statusFor([
            'status' => 'trialing',
            'trial_ends_at' => '2026-08-17 00:00:00',
            'subscription_ends_at' => null,
        ]);

        $this->assertSame('trialing', $status['tenant_status']);
        $this->assertStringStartsWith('2026-08-17', $status['trial_ends_at']);
        $this->assertSame(7, $status['trial_days_remaining']);

        // On a trial, `expires_at` is the trial's own end - the date access
        // runs out, whatever is paying for it. `tenant_status` is what tells a
        // client which of the two it is looking at, which is why the profile
        // card branches on that rather than on the date being present.
        $this->assertStringStartsWith('2026-08-17', $status['expires_at']);
    }

    #[Test]
    public function days_until_expiry_goes_negative_once_the_period_has_passed(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');

        $status = $this->statusFor([
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'subscription_ends_at' => '2026-08-05 00:00:00',
        ]);

        $this->assertLessThan(0, $status['days_until_expiry']);
    }

    /**
     * The older key is still sent: clients depend on it, and a rename that
     * silently drops it would blank the date they already show.
     */
    #[Test]
    public function the_older_expired_at_key_is_kept_alongside_the_new_one(): void
    {
        $status = $this->statusFor([
            'status' => 'active',
            'subscription_ends_at' => '2026-09-01 00:00:00',
        ]);

        $this->assertSame($status['expired_at'], $status['expires_at']);
    }
}
