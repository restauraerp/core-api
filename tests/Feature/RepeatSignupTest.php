<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Somebody signing up again with details we have seen before.
 *
 * The address is verified before this endpoint is ever reached, so a repeat
 * signup is the owner coming back - not a stranger. One person has one
 * restaurant, and the question is only what state to hand it back in.
 */
class RepeatSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['platform.token' => 'test-secret', 'platform.app_url' => 'https://app.test']);
    }

    private function signUp(string $name, string $email): TestResponse
    {
        return $this->withToken(config('platform.token'))
            ->postJson('/api/v1/platform/tenants', [
                'restaurant_name' => $name,
                'owner_name' => 'Rafiq Hasan',
                'email' => $email,
                'plan' => 'starter',
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The same restaurant name, from different people
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_duplicate_name_gets_a_numbered_code(): void
    {
        $this->signUp('Spice Garden', 'first@example.test')
            ->assertCreated()
            ->assertJsonPath('tenant.restaurant_code', 'spice-garden');

        $this->signUp('Spice Garden', 'second@example.test')
            ->assertCreated()
            ->assertJsonPath('tenant.restaurant_code', 'spice-garden-2');

        $this->signUp('Spice Garden', 'third@example.test')
            ->assertCreated()
            ->assertJsonPath('tenant.restaurant_code', 'spice-garden-3');
    }

    /**
     * The unique index does not care about soft deletes, but the scoped query
     * that picks the code cannot see them. Before this was fixed, the signup
     * below died on a duplicate-key error the customer could do nothing about.
     */
    #[Test]
    public function a_name_matching_a_trashed_restaurant_does_not_collide(): void
    {
        $this->signUp('Spice Garden', 'first@example.test')->assertCreated();

        Tenant::where('slug', 'spice-garden')->firstOrFail()->delete();

        $this->signUp('Spice Garden', 'somebody-else@example.test')
            ->assertCreated()
            ->assertJsonPath('tenant.restaurant_code', 'spice-garden-2');
    }

    /*
    |--------------------------------------------------------------------------
    | The same email, coming back
    |--------------------------------------------------------------------------
    */

    private function existing(string $status, bool $trashed = false): Tenant
    {
        $tenant = app(TenantProvisioner::class)->create([
            'name' => 'Spice Garden',
            'plan' => 'starter',
            'status' => $status,
            'contact_email' => 'rafiq@example.test',
            'trial_ends_at' => now()->addDays(7),
        ], [
            'name' => 'Rafiq Hasan',
            'email' => 'rafiq@example.test',
            'password' => bcrypt('secret-password'),
        ]);

        if ($trashed) {
            $tenant->delete();
        }

        return $tenant->fresh();
    }

    #[Test]
    public function a_repeat_signup_returns_the_restaurant_they_already_have(): void
    {
        $existing = $this->existing('active');

        $this->signUp('A Completely Different Name', 'rafiq@example.test')
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('tenant.restaurant_code', $existing->slug)
            // Handed a way in, not left staring at somebody else's restaurant.
            ->assertJsonPath('login.restaurant_code', $existing->slug);

        // One person, one restaurant - not a second one against the same
        // address.
        $this->assertSame(1, Tenant::withTrashed()->where('contact_email', 'rafiq@example.test')->count());
    }

    #[Test]
    public function a_working_account_is_left_exactly_as_it_was(): void
    {
        foreach (['active', 'trialing'] as $status) {
            $existing = $this->existing($status);

            $this->signUp('Spice Garden', 'rafiq@example.test')
                ->assertOk()
                ->assertJsonPath('reactivated', false)
                ->assertJsonPath('tenant.status', $status);

            $existing->forceDelete();
        }
    }

    /**
     * Trashing is a soft delete and never touches `status`, so a restaurant in
     * the trash usually still reads "active". Treating that as a working
     * account would quietly undo the removal.
     */
    #[Test]
    public function a_trashed_restaurant_comes_back_suspended_not_active(): void
    {
        $this->existing('active', trashed: true);

        $this->signUp('Spice Garden', 'rafiq@example.test')
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('reactivated', true)
            ->assertJsonPath('tenant.status', 'suspended');

        $tenant = Tenant::withTrashed()->where('contact_email', 'rafiq@example.test')->sole();

        $this->assertNull($tenant->deleted_at, 'It should be out of the trash.');
        $this->assertSame('suspended', $tenant->status);
    }

    #[Test]
    public function a_cancelled_restaurant_comes_back_suspended(): void
    {
        $this->existing('cancelled');

        $this->signUp('Spice Garden', 'rafiq@example.test')
            ->assertOk()
            ->assertJsonPath('reactivated', true)
            ->assertJsonPath('tenant.status', 'suspended');
    }

    /**
     * The important one. Suspended is the lock behind a rejected payment; a
     * repeat signup must not clear it, or the customer can unlock themselves by
     * filling the form in again.
     */
    #[Test]
    public function a_suspended_restaurant_stays_suspended(): void
    {
        $this->existing('suspended');

        $this->signUp('Spice Garden', 'rafiq@example.test')
            ->assertOk()
            ->assertJsonPath('tenant.status', 'suspended');
    }

    #[Test]
    public function coming_back_never_extends_the_trial(): void
    {
        $existing = $this->existing('trialing');
        $endsAt = $existing->trial_ends_at;

        $this->signUp('Spice Garden', 'rafiq@example.test')->assertOk();

        // Otherwise a fresh trial is one signup form away, for ever.
        $this->assertEquals($endsAt, $existing->fresh()->trial_ends_at);
    }
}
