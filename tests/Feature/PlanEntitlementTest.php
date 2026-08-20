<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What a tenant's subscription actually buys.
 *
 * Every assertion here failed before this work: the outlet cap was declared and
 * never checked, and module entitlement did not exist at all - one restaurant
 * was running five outlets on a two-outlet plan and every tier had all twelve
 * modules.
 */
class PlanEntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are a global catalogue, installed once per database.
        // Without it every role syncs against an empty set and the tier
        // assertions below pass for the wrong reason.
        $this->seed(RolePermissionSeeder::class);
    }

    private function tenantOn(string $plan, array $attributes = [], ?array $owner = null): Tenant
    {
        return app(TenantProvisioner::class)->create(array_merge([
            'name' => ucfirst($plan).' Restaurant',
            'slug' => $plan.'-restaurant',
            'plan' => $plan,
            'status' => 'active',
        ], $attributes), $owner);
    }

    private function actingAsOwnerOf(Tenant $tenant): User
    {
        $user = app(TenantContext::class)->runFor(
            $tenant,
            fn () => User::factory()->create(['tenant_id' => $tenant->getKey()]),
        );

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_a_starter_tenant_cannot_create_a_second_outlet(): void
    {
        $tenant = $this->tenantOn('starter');
        $this->actingAsOwnerOf($tenant);

        // Provisioning already created the head office, so the single outlet
        // Starter includes is spent.
        $this->assertSame(1, $tenant->locations()->count());

        $this->postJson('/api/v1/locations', ['name' => 'Second Branch'], ['X-Tenant-ID' => $tenant->slug])
            ->assertForbidden()
            ->assertJsonPath('error', 'outlet_limit_reached')
            ->assertJsonPath('outlet_limit', 1);

        $this->assertSame(1, $tenant->locations()->count());
    }

    public function test_a_business_tenant_can_create_outlets_up_to_its_cap(): void
    {
        $tenant = $this->tenantOn('business');
        $this->actingAsOwnerOf($tenant);

        // One from provisioning, so two more reach the cap of three.
        $this->postJson('/api/v1/locations', ['name' => 'Second'], ['X-Tenant-ID' => $tenant->slug])
            ->assertCreated();
        $this->postJson('/api/v1/locations', ['name' => 'Third'], ['X-Tenant-ID' => $tenant->slug])
            ->assertCreated();

        $this->postJson('/api/v1/locations', ['name' => 'Fourth'], ['X-Tenant-ID' => $tenant->slug])
            ->assertForbidden()
            ->assertJsonPath('error', 'outlet_limit_reached');

        $this->assertSame(3, $tenant->locations()->count());
    }

    public function test_an_enterprise_tenant_has_no_outlet_cap(): void
    {
        $tenant = $this->tenantOn('enterprise');
        $this->actingAsOwnerOf($tenant);

        $this->assertNull($tenant->outletLimit());

        foreach (['Second', 'Third', 'Fourth', 'Fifth'] as $name) {
            $this->postJson('/api/v1/locations', ['name' => $name], ['X-Tenant-ID' => $tenant->slug])
                ->assertCreated();
        }

        $this->assertFalse($tenant->fresh()->hasReachedOutletLimit());
    }

    public function test_a_grandfathered_tenant_keeps_outlets_above_its_cap_but_cannot_add(): void
    {
        // The shape left by the rename migration: a tenant that collected
        // outlets while nothing enforced the cap.
        $tenant = $this->tenantOn('starter');
        $this->actingAsOwnerOf($tenant);

        app(TenantContext::class)->runFor($tenant, function () {
            Location::create(['name' => 'Grandfathered A', 'slug' => 'gf-a', 'is_active' => true]);
            Location::create(['name' => 'Grandfathered B', 'slug' => 'gf-b', 'is_active' => true]);
        });

        $this->assertSame(3, $tenant->locations()->count());

        // Nothing was deleted, and they are all still readable.
        $this->getJson('/api/v1/locations', ['X-Tenant-ID' => $tenant->slug])
            ->assertOk()
            ->assertJsonCount(3);

        $this->postJson('/api/v1/locations', ['name' => 'One More'], ['X-Tenant-ID' => $tenant->slug])
            ->assertForbidden();
    }

    public function test_starter_is_refused_modules_it_did_not_buy(): void
    {
        $tenant = $this->tenantOn('starter');
        $this->actingAsOwnerOf($tenant);

        // What CRM and HR are actually sold for. `customers` and `users` are
        // deliberately not in this list - see the next test.
        foreach (['reservations', 'loyalty-settings', 'quotations', 'attendances', 'payrolls', 'deliveries'] as $endpoint) {
            $this->getJson("/api/v1/{$endpoint}", ['X-Tenant-ID' => $tenant->slug])
                ->assertForbidden()
                ->assertJsonPath('error', 'module_not_in_plan');
        }
    }

    /**
     * The part of CRM and HR that is not an upsell.
     *
     * A Starter restaurant types customer details into the till every day and
     * hires staff who need logins. Being unable to read back its own customer
     * list, or to create an employee, is a broken account rather than a cheaper
     * one - so those endpoints sit outside the module gate. See
     * Modules::ESSENTIAL.
     */
    public function test_starter_reaches_its_own_customers_and_staff(): void
    {
        $tenant = $this->tenantOn('starter');
        $this->actingAsOwnerOf($tenant);

        foreach (['customers', 'customers-export', 'users', 'roles'] as $endpoint) {
            $this->getJson("/api/v1/{$endpoint}", ['X-Tenant-ID' => $tenant->slug])
                ->assertOk();
        }
    }

    /**
     * Reading the list back is half of it; the till has to be able to add to it.
     *
     * A cashier taking a phone number for a delivery, or naming the customer an
     * order is going on account for, is creating a customer record - and an
     * order cannot be put on account without one. A Starter restaurant refused
     * here would be unable to take a delivery order or let a regular settle up
     * later, which is a broken account rather than a cheaper one.
     */
    public function test_starter_can_record_a_customer_at_the_till(): void
    {
        $tenant = $this->tenantOn('starter');
        $this->actingAsOwnerOf($tenant);

        $id = $this->postJson('/api/v1/customers', [
            'name' => 'Rahim Ahmed',
            'phone' => '01711000027',
        ], ['X-Tenant-ID' => $tenant->slug])
            ->assertCreated()
            ->json('id');

        // And can find, correct and remove what it recorded.
        $this->getJson("/api/v1/customers/{$id}", ['X-Tenant-ID' => $tenant->slug])
            ->assertOk()
            ->assertJsonPath('name', 'Rahim Ahmed');

        $this->putJson("/api/v1/customers/{$id}", [
            'name' => 'Rahim Ahmed',
            'phone' => '01711000027',
            'address' => 'Gulshan, Dhaka',
        ], ['X-Tenant-ID' => $tenant->slug])->assertOk();

        $this->deleteJson("/api/v1/customers/{$id}", [], ['X-Tenant-ID' => $tenant->slug])
            ->assertSuccessful();
    }

    /**
     * The loyalty scheme built on top of the address book is still an upsell.
     */
    public function test_starter_recording_a_customer_does_not_unlock_crm(): void
    {
        $tenant = $this->tenantOn('starter');
        $this->actingAsOwnerOf($tenant);

        $this->postJson('/api/v1/customers', [
            'name' => 'Rahim Ahmed',
            'phone' => '01711000027',
        ], ['X-Tenant-ID' => $tenant->slug])->assertCreated();

        $this->getJson('/api/v1/loyalty-settings', ['X-Tenant-ID' => $tenant->slug])
            ->assertForbidden()
            ->assertJsonPath('error', 'module_not_in_plan');
    }

    public function test_starter_keeps_the_six_core_modules(): void
    {
        $tenant = $this->tenantOn('starter');
        $this->actingAsOwnerOf($tenant);

        foreach (['orders', 'inventory-items', 'expenses'] as $endpoint) {
            $this->getJson("/api/v1/{$endpoint}", ['X-Tenant-ID' => $tenant->slug])
                ->assertOk();
        }
    }

    public function test_growth_reaches_the_modules_starter_cannot(): void
    {
        $tenant = $this->tenantOn('growth');
        $this->actingAsOwnerOf($tenant);

        // `customers` is deliberately absent: every tier reaches it now, so it
        // would prove nothing about what Growth adds.
        foreach (['reservations', 'loyalty-settings', 'attendances', 'payrolls', 'deliveries'] as $endpoint) {
            $this->getJson("/api/v1/{$endpoint}", ['X-Tenant-ID' => $tenant->slug])
                ->assertOk();
        }
    }

    public function test_owner_permissions_are_capped_by_the_tier(): void
    {
        $starter = $this->tenantOn('starter');
        $growth = $this->tenantOn('growth', ['name' => 'Growth Co', 'slug' => 'growth-co']);

        $permissionsFor = function (Tenant $tenant): array {
            return app(TenantContext::class)->runFor($tenant, function () {
                $user = User::factory()->create();
                $user->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

                return $user->getAllPermissions()->pluck('name')->all();
            });
        };

        $starterPermissions = $permissionsFor($starter);
        $growthPermissions = $permissionsFor($growth);

        $this->assertContains('view_pos', $starterPermissions);

        // Starter sees its own customers and staff, and nothing else either
        // module sells. This pair of assertions is what keeps Growth worth
        // buying: grant manage_loyalty_settings or manage_payroll to Starter
        // and there are only four modules left to charge three times as much
        // for.
        $this->assertContains('view_crm', $starterPermissions);
        $this->assertContains('manage_customers', $starterPermissions);
        $this->assertContains('view_hr', $starterPermissions);
        $this->assertContains('manage_employees', $starterPermissions);

        $this->assertNotContains('manage_loyalty_settings', $starterPermissions);
        $this->assertNotContains('manage_attendance', $starterPermissions);
        $this->assertNotContains('manage_leaves', $starterPermissions);
        $this->assertNotContains('manage_payroll', $starterPermissions);

        // A restaurant must always be able to correct its own address, even on
        // a tier that does not sell multi-branch management.
        $this->assertContains('view_locations', $starterPermissions);
        $this->assertContains('update_location', $starterPermissions);
        $this->assertNotContains('create_location', $starterPermissions);

        $this->assertContains('manage_loyalty_settings', $growthPermissions);
        $this->assertContains('manage_payroll', $growthPermissions);
        $this->assertGreaterThan(count($starterPermissions), count($growthPermissions));
    }

    public function test_changing_plan_resyncs_limits_and_permissions(): void
    {
        $tenant = $this->tenantOn('starter');

        $this->artisan('tenants:plan', ['tenant' => $tenant->slug, 'plan' => 'business'])
            ->assertSuccessful();

        $tenant->refresh();

        $this->assertSame('business', $tenant->plan);
        $this->assertSame(3, $tenant->outletLimit());

        $permissions = app(TenantContext::class)->runFor($tenant, function () {
            $user = User::factory()->create();
            $user->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

            return $user->getAllPermissions()->pluck('name')->all();
        });

        $this->assertContains('view_crm', $permissions);
    }

    public function test_a_lapsed_trial_goes_read_only_before_the_expiry_sweep_runs(): void
    {
        $tenant = $this->tenantOn('starter', [
            'status' => 'trialing',
            'trial_ends_at' => now()->subDay(),
        ]);
        $this->actingAsOwnerOf($tenant);

        $this->assertFalse($tenant->isActive());

        // Status still says "trialing" - the point is that the restriction does
        // not wait for tenants:expire to correct it. Reads keep working; see
        // SubscriptionLifecycleTest for the rest of the state machine.
        $this->getJson('/api/v1/locations', ['X-Tenant-ID' => $tenant->slug])
            ->assertOk();

        $this->postJson('/api/v1/locations', ['name' => 'New'], ['X-Tenant-ID' => $tenant->slug])
            ->assertForbidden()
            ->assertJsonPath('error', 'trial_expired');
    }

    public function test_a_tenant_with_no_end_date_is_not_treated_as_expired(): void
    {
        $tenant = $this->tenantOn('starter', ['status' => 'active', 'subscription_ends_at' => null]);

        $this->assertTrue($tenant->isActive());
        $this->assertFalse($tenant->hasLapsed());
    }

    public function test_expire_command_suspends_only_lapsed_tenants(): void
    {
        $lapsed = $this->tenantOn('starter', [
            'status' => 'trialing',
            'trial_ends_at' => now()->subDay(),
        ]);
        $running = $this->tenantOn('growth', [
            'name' => 'Still Running',
            'slug' => 'still-running',
            'status' => 'trialing',
            'trial_ends_at' => now()->addWeek(),
        ]);

        $this->artisan('tenants:expire')->assertSuccessful();

        $this->assertSame('suspended', $lapsed->fresh()->status);
        $this->assertSame('trialing', $running->fresh()->status);
    }

    /**
     * The owner must hold their OWN restaurant's role.
     *
     * Assigning by name asks Spatie to resolve one, and its team scope counts a
     * role with team_id NULL as global - so a single stray global row named
     * restaurant_admin outranked the tenant's capped copy and every Starter
     * owner silently inherited all twelve modules. The dashboard's quick access
     * menu renders from this permission list, which is where it showed.
     */
    public function test_a_stray_global_role_does_not_leak_modules_to_a_starter_owner(): void
    {
        // Exactly the row the old context-less createRoles() produced: a tenant
        // role belonging to no tenant, carrying every permission there is.
        $global = Role::create([
            'name' => RoleDefinitions::RESTAURANT_ADMIN,
            'guard_name' => 'web',
            'tenant_id' => null,
        ]);
        $global->syncPermissions(Permission::all());

        $tenant = $this->tenantOn('starter', [
            'name' => 'Owner Role Restaurant',
            'slug' => 'owner-role-restaurant',
        ], owner: ['email' => 'owner@starter.test', 'name' => 'Starter Owner']);

        $permissions = app(TenantContext::class)->runFor($tenant, function () {
            return User::where('email', 'owner@starter.test')
                ->firstOrFail()
                ->getAllPermissions()
                ->pluck('name')
                ->all();
        });

        // Starter's six core modules, and none of what the other six sell.
        //
        // The canary is view_delivery, not view_crm: Starter legitimately holds
        // view_crm now for its own customer list, so it would no longer catch a
        // leak. Delivery is untouched by that split and stays a clean signal.
        $this->assertContains('view_pos', $permissions);
        $this->assertContains('view_accounting', $permissions);
        $this->assertNotContains('view_delivery', $permissions);
        $this->assertNotContains('view_kitchen_kiosk', $permissions);
        $this->assertNotContains('view_website', $permissions);
        // view_hr is deliberately absent from this list - Starter holds it now
        // so it can create an employee. What HR sells is checked instead.
        $this->assertNotContains('manage_payroll', $permissions);
    }

    /**
     * Creating tenant roles with nothing to scope them to is how the stray
     * global rows appeared in the first place - and with no plan to read they
     * were granted everything. Refusing beats guessing.
     */
    public function test_creating_tenant_roles_without_a_tenant_is_refused(): void
    {
        $this->expectException(\LogicException::class);

        app(TenantProvisioner::class)->createRoles();
    }

    public function test_repair_command_repoints_owners_and_removes_stray_global_roles(): void
    {
        $tenant = $this->tenantOn('starter', [
            'name' => 'Repair Restaurant',
            'slug' => 'repair-restaurant',
        ], owner: ['email' => 'owner@repair.test', 'name' => 'Repair Owner']);

        // Recreate the damage: a global role holding everything, with the owner
        // moved onto it.
        $global = Role::create([
            'name' => RoleDefinitions::RESTAURANT_ADMIN,
            'guard_name' => 'web',
            'tenant_id' => null,
        ]);
        $global->syncPermissions(Permission::all());

        $owner = User::where('email', 'owner@repair.test')->firstOrFail();
        DB::table('model_has_roles')
            ->where('model_id', $owner->getKey())
            ->update(['role_id' => $global->getKey()]);

        $this->artisan('tenants:repair-roles')->assertSuccessful();

        $this->assertDatabaseMissing('roles', [
            'id' => $global->getKey(),
        ]);

        $permissions = app(TenantContext::class)->runFor(
            $tenant,
            fn () => $owner->fresh()->getAllPermissions()->pluck('name')->all(),
        );

        $this->assertContains('view_pos', $permissions);
        // view_delivery rather than view_crm - see the note above.
        $this->assertNotContains('view_delivery', $permissions);
    }
}
