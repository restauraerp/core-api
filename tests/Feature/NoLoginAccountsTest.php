<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Accounts that exist without signing in - a waiter credited with sales.
 *
 * Whether someone signs in is set per role (roles.requires_login, the
 * default for new users given it) and per user (users.can_login, which
 * decides). A user who does not sign in needs neither email nor password.
 */
class NoLoginAccountsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Waiters', 'slug' => 'waiters', 'plan' => 'growth', 'status' => 'active',
        ]);

        $this->owner = $this->inTenant(function () {
            $owner = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $owner->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

            return $owner;
        });

        Sanctum::actingAs($this->owner);
        $this->withHeader('X-Tenant-ID', 'waiters');
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function role(string $name): Role
    {
        return Role::where('tenant_id', $this->tenant->getKey())->where('name', $name)->firstOrFail();
    }

    #[Test]
    public function new_restaurants_get_a_waiter_role_that_does_not_sign_in(): void
    {
        $this->assertFalse((bool) $this->role('waiter')->requires_login);
        $this->assertTrue((bool) $this->role('pos_manager')->requires_login);
        $this->assertTrue($this->role('waiter')->hasPermissionTo('view_pos'));
    }

    #[Test]
    public function a_waiter_is_created_without_email_or_password(): void
    {
        $id = $this->postJson('/api/v1/users', ['name' => 'Rafi Waiter', 'role' => 'waiter'])
            ->assertCreated()
            ->assertJsonPath('can_login', false)
            ->json('id');

        $waiter = $this->inTenant(fn () => User::findOrFail($id));
        $this->assertNull($waiter->email);
        $this->assertFalse($waiter->canLogIn());
        $this->assertTrue($this->inTenant(fn () => $waiter->hasRole('waiter')));
    }

    #[Test]
    public function the_user_level_setting_overrides_the_role(): void
    {
        $this->postJson('/api/v1/users', [
            'name' => 'Head Waiter', 'role' => 'waiter', 'can_login' => true,
            'email' => 'head@waiters.test', 'password' => 'secret-password',
        ])->assertCreated()->assertJsonPath('can_login', true);

        // A role that signs in, with a user who does not.
        $this->postJson('/api/v1/users', ['name' => 'Cashier Only On Paper', 'role' => 'pos_manager', 'can_login' => false])
            ->assertCreated()
            ->assertJsonPath('can_login', false);
    }

    #[Test]
    public function signing_in_still_needs_an_email_and_password(): void
    {
        $this->postJson('/api/v1/users', ['name' => 'No Email', 'role' => 'pos_manager'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);

        $id = $this->postJson('/api/v1/users', ['name' => 'Rafi', 'role' => 'waiter'])->json('id');

        // Switching sign-in on for someone with no email asks for one.
        $this->putJson("/api/v1/users/{$id}", ['can_login' => true, 'password' => 'secret-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->putJson("/api/v1/users/{$id}", ['can_login' => true, 'password' => 'secret-password', 'email' => 'rafi@waiters.test'])
            ->assertOk()
            ->assertJsonPath('can_login', true);

        // And a signed-in user cannot have their email taken away.
        $this->putJson("/api/v1/users/{$id}", ['email' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function a_no_login_waiter_cannot_sign_in(): void
    {
        $this->inTenant(function () {
            $waiter = User::factory()->create([
                'tenant_id' => $this->tenant->getKey(), 'email' => 'quiet@waiters.test', 'can_login' => false,
            ]);
            $waiter->assignRole('waiter');
        });

        $this->postJson('/api/v1/auth/login', ['email' => 'quiet@waiters.test', 'password' => 'password'])
            ->assertUnprocessable();
    }

    #[Test]
    public function the_sign_in_default_is_set_on_the_role(): void
    {
        $role = $this->postJson('/api/v1/roles', ['name' => 'runner', 'requires_login' => false])
            ->assertCreated()
            ->json();
        $this->assertFalse((bool) $role['requires_login']);

        $this->postJson('/api/v1/users', ['name' => 'Food Runner', 'role' => 'runner'])
            ->assertCreated()
            ->assertJsonPath('can_login', false);

        $this->putJson("/api/v1/roles/{$role['id']}", ['requires_login' => true])->assertOk();
        $this->postJson('/api/v1/users', ['name' => 'Needs Login', 'role' => 'runner'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function the_owner_role_always_signs_in(): void
    {
        $this->putJson("/api/v1/roles/{$this->role(RoleDefinitions::RESTAURANT_ADMIN)->id}", ['requires_login' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('requires_login');
    }

    #[Test]
    public function existing_restaurants_are_given_new_roles_without_touching_their_own(): void
    {
        // A restaurant from before the waiter role, which has adjusted its
        // pos_manager and switched its sign-in off.
        $this->role('waiter')->delete();
        $posManager = $this->role('pos_manager');
        $posManager->syncPermissions(['view_pos']);
        $posManager->forceFill(['requires_login' => false])->save();

        $this->artisan('tenants:sync-roles', ['--tenant' => 'waiters', '--dry-run' => true])
            ->expectsOutputToContain('would add [waiters]: waiter')
            ->assertSuccessful();
        $this->assertNull(Role::where('tenant_id', $this->tenant->getKey())->where('name', 'waiter')->first());

        $this->artisan('tenants:sync-roles')->assertSuccessful();

        $this->assertFalse((bool) $this->role('waiter')->requires_login);
        $posManager = $this->role('pos_manager')->fresh();
        $this->assertSame(['view_pos'], $posManager->permissions->pluck('name')->all());
        $this->assertFalse((bool) $posManager->requires_login);

        // Nothing left to add.
        $this->artisan('tenants:sync-roles')->expectsOutputToContain('0 role(s)')->assertSuccessful();
    }
}
