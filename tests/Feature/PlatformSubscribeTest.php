<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The platform subscribe endpoint, and the start date an admin can set on it.
 *
 * The default behaviour - extend from where the current period ends - is what
 * a customer's own payment uses. `starts_at` is the admin's override for money
 * that arrived before anyone got round to entering it, and its edge is a period
 * back-dated so far that it has already run out.
 */
class PlatformSubscribeTest extends TestCase
{
    use RefreshDatabase;

    private function subscribe(Tenant $tenant, array $payload): TestResponse
    {
        return $this->withToken(config('platform.token'))
            ->postJson("/api/v1/platform/tenants/{$tenant->slug}/subscribe", $payload);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['platform.token' => 'test-secret']);
    }

    #[Test]
    public function without_a_start_date_it_runs_from_today(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');

        $tenant = Tenant::factory()->create(['status' => 'trialing', 'subscription_ends_at' => null]);

        $this->subscribe($tenant, ['cycle' => 'monthly'])->assertOk();

        $this->assertSame('2026-09-10', $tenant->fresh()->subscription_ends_at->toDateString());
        $this->assertSame('active', $tenant->fresh()->status);
    }

    #[Test]
    public function a_running_subscription_is_extended_from_where_it_ends(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');

        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_ends_at' => '2026-08-25 00:00:00',
        ]);

        $this->subscribe($tenant, ['cycle' => 'monthly'])->assertOk();

        // Paying early must not cost them the days they already had.
        $this->assertSame('2026-09-25', $tenant->fresh()->subscription_ends_at->toDateString());
    }

    #[Test]
    public function a_start_date_runs_the_period_from_that_day(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');

        $tenant = Tenant::factory()->create(['status' => 'trialing', 'subscription_ends_at' => null]);

        $this->subscribe($tenant, ['cycle' => 'monthly', 'starts_at' => '2026-07-20'])
            ->assertOk()
            ->assertJsonPath('lapsed', false);

        $this->assertSame('2026-08-20', $tenant->fresh()->subscription_ends_at->toDateString());
        $this->assertSame('active', $tenant->fresh()->status);
    }

    #[Test]
    public function a_start_date_replaces_a_running_period_rather_than_extending_it(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');

        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_ends_at' => '2026-12-31 00:00:00',
        ]);

        $this->subscribe($tenant, ['cycle' => 'monthly', 'starts_at' => '2026-08-01'])->assertOk();

        // Not 2027-01-31: the admin is stating when this period began, and
        // adding it on top would hand out time nobody paid for.
        $this->assertSame('2026-09-01', $tenant->fresh()->subscription_ends_at->toDateString());
    }

    #[Test]
    public function a_yearly_cycle_runs_a_year_from_the_start_date(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');

        $tenant = Tenant::factory()->create(['status' => 'trialing']);

        $this->subscribe($tenant, ['cycle' => 'yearly', 'starts_at' => '2026-08-05'])->assertOk();

        $this->assertSame('2027-08-05', $tenant->fresh()->subscription_ends_at->toDateString());
    }

    #[Test]
    public function a_period_that_has_already_ended_does_not_switch_the_account_on(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');

        $tenant = Tenant::factory()->create(['status' => 'trialing', 'subscription_ends_at' => null]);

        $this->subscribe($tenant, ['cycle' => 'monthly', 'starts_at' => '2026-01-01'])
            ->assertOk()
            ->assertJsonPath('lapsed', true);

        $fresh = $tenant->fresh();

        // The period is recorded, because the money really was received...
        $this->assertSame('2026-02-01', $fresh->subscription_ends_at->toDateString());
        // ...but a subscription that ran out in February grants nothing today.
        $this->assertSame('trialing', $fresh->status);
    }

    #[Test]
    public function recording_an_elapsed_period_over_a_live_subscription_suspends_it(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');

        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_ends_at' => '2026-12-31 00:00:00',
        ]);

        $this->subscribe($tenant, ['cycle' => 'monthly', 'starts_at' => '2026-01-01'])
            ->assertOk()
            ->assertJsonPath('lapsed', true);

        // The window its access rested on is gone, so the status must stop
        // claiming a subscription - the same end tenants:expire would reach.
        $this->assertSame('suspended', $tenant->fresh()->status);
    }

    #[Test]
    public function a_live_trial_survives_an_elapsed_period_being_recorded(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');

        $tenant = Tenant::factory()->create([
            'status' => 'trialing',
            'trial_ends_at' => '2026-08-30 00:00:00',
        ]);

        $this->subscribe($tenant, ['cycle' => 'monthly', 'starts_at' => '2026-01-01'])->assertOk();

        // The trial is real and is not what this old payment replaced.
        $this->assertSame('trialing', $tenant->fresh()->status);
    }

    #[Test]
    public function a_malformed_start_date_is_refused(): void
    {
        $tenant = Tenant::factory()->create();

        $this->subscribe($tenant, ['cycle' => 'monthly', 'starts_at' => 'whenever'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
