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

class RoleSyncPredefinedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Sync Test Restaurant',
            'slug' => 'sync-test-restaurant',
            'plan' => 'growth',
            'status' => 'active',
        ]);

        $this->owner = $this->inTenant(function () {
            $owner = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $owner->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

            return $owner;
        });

        Sanctum::actingAs($this->owner);
        $this->withHeader('X-Tenant-ID', 'sync-test-restaurant');
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function role(string $name): ?Role
    {
        return Role::where('tenant_id', $this->tenant->getKey())->where('name', $name)->first();
    }

    #[Test]
    public function sync_predefined_roles_adds_missing_roles(): void
    {
        // Simulate an existing restaurant that lacks the 'waiter' role
        $this->role('waiter')?->delete();
        $this->assertNull($this->role('waiter'));

        $response = $this->postJson('/api/v1/roles/sync-predefined')
            ->assertOk()
            ->assertJsonPath('added', ['waiter']);

        $waiterRole = $this->role('waiter');
        $this->assertNotNull($waiterRole);
        $this->assertFalse((bool) $waiterRole->requires_login);
        $this->assertTrue($waiterRole->hasPermissionTo('view_pos'));
    }

    #[Test]
    public function sync_predefined_roles_is_idempotent_when_none_are_missing(): void
    {
        // When all roles exist
        $response = $this->postJson('/api/v1/roles/sync-predefined')
            ->assertOk()
            ->assertJsonPath('added', []);
    }

    #[Test]
    public function sync_predefined_roles_preserves_customizations_on_existing_roles(): void
    {
        // Delete waiter so there is something to sync
        $this->role('waiter')?->delete();

        // Customise pos_manager
        $posManager = $this->role('pos_manager');
        $posManager->syncPermissions(['view_pos']);
        $posManager->forceFill(['requires_login' => false])->save();

        $this->postJson('/api/v1/roles/sync-predefined')
            ->assertOk()
            ->assertJsonPath('added', ['waiter']);

        $posManager = $this->role('pos_manager')->fresh();
        $this->assertSame(['view_pos'], $posManager->permissions->pluck('name')->all());
        $this->assertFalse((bool) $posManager->requires_login);
    }

    #[Test]
    public function unauthorized_user_cannot_sync_predefined_roles(): void
    {
        $staff = $this->inTenant(function () {
            $user = User::factory()->create([
                'tenant_id' => $this->tenant->getKey(),
                'email' => 'chef@test.local',
                'can_login' => true,
            ]);
            $user->assignRole('chef');

            return $user;
        });

        Sanctum::actingAs($staff);

        $this->postJson('/api/v1/roles/sync-predefined')
            ->assertForbidden();
    }
}
