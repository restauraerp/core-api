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
use Laravel\Sanctum\Sanctum;
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

    private function tenantOn(string $plan, array $attributes = []): Tenant
    {
        return app(TenantProvisioner::class)->create(array_merge([
            'name' => ucfirst($plan).' Restaurant',
            'slug' => $plan.'-restaurant',
            'plan' => $plan,
            'status' => 'active',
        ], $attributes));
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

        foreach (['customers', 'attendances', 'deliveries'] as $endpoint) {
            $this->getJson("/api/v1/{$endpoint}", ['X-Tenant-ID' => $tenant->slug])
                ->assertForbidden()
                ->assertJsonPath('error', 'module_not_in_plan');
        }
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

        foreach (['customers', 'attendances', 'deliveries'] as $endpoint) {
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

        $this->assertNotContains('view_crm', $starterPermissions);
        $this->assertNotContains('view_hr', $starterPermissions);
        $this->assertContains('view_pos', $starterPermissions);

        // A restaurant must always be able to correct its own address, even on
        // a tier that does not sell multi-branch management.
        $this->assertContains('view_locations', $starterPermissions);
        $this->assertContains('update_location', $starterPermissions);
        $this->assertNotContains('create_location', $starterPermissions);

        $this->assertContains('view_crm', $growthPermissions);
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
}
