<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The support path for a locked-out owner: tenants:reset-password.
 *
 * What matters here is that the reset reaches the right user - the command runs
 * outside any request, where the tenant scope is off unless it is set
 * explicitly - and that the old credentials stop working.
 */
class TenantPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = Tenant::factory()->create(['slug' => 'reset-test', 'plan' => 'enterprise']);

        app(TenantContext::class)->runFor(
            $this->tenant,
            fn () => app(TenantProvisioner::class)->createRoles($this->tenant),
        );
    }

    private function userFor(Tenant $tenant, string $email, ?string $role = null): User
    {
        return app(TenantContext::class)->runFor($tenant, function () use ($tenant, $email, $role) {
            $user = User::factory()->forTenant($tenant)->create([
                'email' => $email,
                'password' => 'old-password',
            ]);

            if ($role !== null) {
                $user->assignRole($role);
            }

            return $user;
        });
    }

    private function owner(string $email = 'owner@reset-test.test'): User
    {
        return $this->userFor($this->tenant, $email, RoleDefinitions::RESTAURANT_ADMIN);
    }

    public function test_it_resets_the_sole_admin_when_no_email_is_given(): void
    {
        $owner = $this->owner();

        $this->artisan('tenants:reset-password', ['tenant' => 'reset-test', '--password' => 'new-password', '--force' => true])
            ->assertSuccessful();

        $this->assertTrue(Hash::check('new-password', $owner->fresh()->password));
    }

    public function test_it_accepts_a_tenant_id_as_well_as_a_slug(): void
    {
        $owner = $this->owner();

        $this->artisan('tenants:reset-password', ['tenant' => (string) $this->tenant->id, '--password' => 'new-password', '--force' => true])
            ->assertSuccessful();

        $this->assertTrue(Hash::check('new-password', $owner->fresh()->password));
    }

    public function test_it_revokes_sessions_and_api_tokens(): void
    {
        $owner = $this->owner();
        $owner->createToken('phone');

        $this->artisan('tenants:reset-password', ['tenant' => 'reset-test', '--password' => 'new-password', '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $owner->id)
            ->count());
    }

    public function test_keep_sessions_leaves_existing_tokens_alone(): void
    {
        $owner = $this->owner();
        $owner->createToken('phone');

        $this->artisan('tenants:reset-password', [
            'tenant' => 'reset-test',
            '--password' => 'new-password',
            '--keep-sessions' => true,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(1, DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $owner->id)
            ->count());
    }

    public function test_email_targets_a_specific_user(): void
    {
        $owner = $this->owner();
        $manager = $this->userFor($this->tenant, 'manager@reset-test.test');

        $this->artisan('tenants:reset-password', [
            'tenant' => 'reset-test',
            '--email' => 'manager@reset-test.test',
            '--password' => 'new-password',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertTrue(Hash::check('new-password', $manager->fresh()->password));
        $this->assertTrue(Hash::check('old-password', $owner->fresh()->password), 'The owner was reset instead of the named user.');
    }

    public function test_it_refuses_a_user_belonging_to_another_tenant(): void
    {
        $other = Tenant::factory()->create(['slug' => 'other-tenant']);
        $stranger = $this->userFor($other, 'stranger@other.test');

        $this->artisan('tenants:reset-password', [
            'tenant' => 'reset-test',
            '--email' => 'stranger@other.test',
            '--password' => 'new-password',
            '--force' => true,
        ])->assertFailed();

        $this->assertTrue(Hash::check('old-password', $stranger->fresh()->password));
    }

    public function test_it_refuses_to_guess_when_the_tenant_has_several_admins(): void
    {
        $first = $this->owner('first@reset-test.test');
        $second = $this->owner('second@reset-test.test');

        $this->artisan('tenants:reset-password', ['tenant' => 'reset-test', '--password' => 'new-password', '--force' => true])
            ->assertFailed();

        $this->assertTrue(Hash::check('old-password', $first->fresh()->password));
        $this->assertTrue(Hash::check('old-password', $second->fresh()->password));
    }

    public function test_it_fails_when_the_tenant_has_no_admin_and_no_email_is_given(): void
    {
        $this->userFor($this->tenant, 'waiter@reset-test.test');

        $this->artisan('tenants:reset-password', ['tenant' => 'reset-test', '--password' => 'new-password', '--force' => true])
            ->assertFailed();
    }

    public function test_it_rejects_a_password_shorter_than_the_api_allows(): void
    {
        $owner = $this->owner();

        $this->artisan('tenants:reset-password', ['tenant' => 'reset-test', '--password' => 'short', '--force' => true])
            ->assertFailed();

        $this->assertTrue(Hash::check('old-password', $owner->fresh()->password));
    }

    public function test_it_fails_on_an_unknown_tenant(): void
    {
        $this->artisan('tenants:reset-password', ['tenant' => 'no-such-tenant', '--force' => true])
            ->assertFailed();
    }

    public function test_declining_the_prompt_changes_nothing(): void
    {
        $owner = $this->owner();

        $this->artisan('tenants:reset-password', ['tenant' => 'reset-test', '--password' => 'new-password'])
            ->expectsConfirmation('Reset the password for [owner@reset-test.test]?', 'no')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('old-password', $owner->fresh()->password));
    }
}
