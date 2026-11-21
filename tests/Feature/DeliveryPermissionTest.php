<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\Modules;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DeliveryPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure global permission exists for test environment
        Permission::findOrCreate('deliver_orders', 'web');

        $this->tenant = Tenant::factory()->create([
            'slug' => 'delivery-perm-test',
            'plan' => 'enterprise',
        ]);

        $this->admin = User::factory()->forTenant($this->tenant)->create([
            'name' => 'Tenant Admin',
            'email' => 'admin@delivery-test.com',
        ]);

        app(TenantContext::class)->set($this->tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        Sanctum::actingAs($this->admin);
    }

    public function test_deliver_orders_is_registered_in_role_definitions_and_modules(): void
    {
        $permissions = RoleDefinitions::permissions();
        $this->assertContains('deliver_orders', $permissions);

        $riderRolePerms = RoleDefinitions::tenantRoles()['rider'];
        $this->assertContains('deliver_orders', $riderRolePerms);

        $deliveryModulePerms = Modules::permissions()[Modules::DELIVERY];
        $this->assertContains('deliver_orders', $deliveryModulePerms);
    }

    public function test_user_can_deliver_attribute_with_rider_role(): void
    {
        $rider = User::factory()->forTenant($this->tenant)->create(['name' => 'Fast Rider']);

        $riderRole = Role::findOrCreate('rider', 'web');
        $rider->assignRole($riderRole);

        $this->assertTrue($rider->can_deliver);
        $this->assertTrue($rider->toArray()['can_deliver']);
    }

    public function test_user_can_deliver_attribute_with_direct_permission(): void
    {
        $waiter = User::factory()->forTenant($this->tenant)->create(['name' => 'Waiter Dave']);

        $this->assertFalse($waiter->can_deliver);

        $waiter->givePermissionTo('deliver_orders');
        $waiter->unsetRelation('permissions');
        $waiter->unsetRelation('roles');

        $this->assertTrue($waiter->can_deliver);
        $this->assertTrue($waiter->toArray()['can_deliver']);
    }

    public function test_user_can_deliver_attribute_with_role_permission(): void
    {
        $customRole = Role::findOrCreate('custom_driver', 'web');
        $customRole->givePermissionTo('deliver_orders');

        $user = User::factory()->forTenant($this->tenant)->create(['name' => 'Custom Driver']);
        $user->assignRole($customRole);

        $this->assertTrue($user->can_deliver);
    }

    public function test_user_without_permission_has_can_deliver_false(): void
    {
        $cashier = User::factory()->forTenant($this->tenant)->create(['name' => 'Cashier Alice']);

        $this->assertFalse($cashier->can_deliver);
        $this->assertFalse($cashier->toArray()['can_deliver']);
    }

    public function test_user_store_creates_user_with_can_deliver_true(): void
    {
        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Driver',
            'email' => 'driver@example.com',
            'password' => 'secret123',
            'can_deliver' => true,
        ]);

        $response->assertCreated();
        $this->assertTrue($response->json('can_deliver'));

        $newUser = User::where('email', 'driver@example.com')->first();
        $this->assertNotNull($newUser);
        $this->assertTrue($newUser->can_deliver);
        $this->assertTrue($newUser->hasPermissionTo('deliver_orders'));
    }

    public function test_user_store_creates_user_with_can_deliver_false(): void
    {
        $response = $this->postJson('/api/v1/users', [
            'name' => 'Regular Staff',
            'email' => 'staff@example.com',
            'password' => 'secret123',
            'can_deliver' => false,
        ]);

        $response->assertCreated();
        $this->assertFalse($response->json('can_deliver'));

        $newUser = User::where('email', 'staff@example.com')->first();
        $this->assertNotNull($newUser);
        $this->assertFalse($newUser->can_deliver);
        $this->assertFalse($newUser->hasPermissionTo('deliver_orders'));
    }

    public function test_user_update_toggles_can_deliver(): void
    {
        $staff = User::factory()->forTenant($this->tenant)->create([
            'name' => 'Existing Staff',
            'email' => 'existing@example.com',
        ]);

        $this->assertFalse($staff->can_deliver);

        // Turn ON delivery permission
        $updateOnResponse = $this->putJson("/api/v1/users/{$staff->id}", [
            'name' => 'Existing Staff Promoted',
            'can_deliver' => true,
        ]);

        $updateOnResponse->assertOk();
        $this->assertTrue($updateOnResponse->json('can_deliver'));

        $staff->refresh();
        $this->assertTrue($staff->can_deliver);
        $this->assertTrue($staff->hasPermissionTo('deliver_orders'));

        // Turn OFF delivery permission
        $updateOffResponse = $this->putJson("/api/v1/users/{$staff->id}", [
            'can_deliver' => false,
        ]);

        $updateOffResponse->assertOk();
        $this->assertFalse($updateOffResponse->json('can_deliver'));

        $staff->refresh();
        $this->assertFalse($staff->can_deliver);
        $this->assertFalse($staff->hasPermissionTo('deliver_orders'));
    }

    public function test_user_index_returns_can_deliver_field(): void
    {
        $rider = User::factory()->forTenant($this->tenant)->create(['name' => 'Indexed Rider']);
        $rider->givePermissionTo('deliver_orders');

        $response = $this->getJson('/api/v1/users');
        $response->assertOk();

        $users = collect($response->json('data'));
        $riderData = $users->firstWhere('id', $rider->id);
        $this->assertNotNull($riderData);
        $this->assertTrue($riderData['can_deliver']);
    }
}
