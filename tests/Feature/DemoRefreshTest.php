<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The guards on demo:refresh, which are the whole point of the command.
 *
 * The refresh itself is not exercised here: DemoSeeder inserts two years of
 * orders and takes minutes, which does not belong in the default suite. What is
 * tested is everything that decides *whether* and *what* it destroys - the part
 * where a mistake costs somebody their restaurant.
 */
class DemoRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.demo_mode', true);
        Config::set('app.demo_tenant_slug', 'demo-restaurant');
        Config::set('app.demo_legacy_tenant_slugs', ['legacy-restaurant']);
        Config::set('app.install_tenant_slug', 'default');
    }

    public function test_it_refuses_when_demo_mode_is_off(): void
    {
        Config::set('app.demo_mode', false);

        $tenant = Tenant::factory()->create(['slug' => 'demo-restaurant']);

        $this->artisan('demo:refresh', ['--force' => true])
            ->expectsOutputToContain('only runs on a demo deployment')
            ->assertFailed();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'deleted_at' => null]);
    }

    public function test_if_demo_turns_a_non_demo_box_into_a_no_op(): void
    {
        Config::set('app.demo_mode', false);

        $tenant = Tenant::factory()->create(['slug' => 'demo-restaurant']);

        // Exit 0 is what lets one deploy pipeline and one cron line be shipped
        // to every box, demo or not, without failing the ones that are not.
        $this->artisan('demo:refresh', ['--force' => true, '--if-demo' => true])
            ->expectsOutputToContain('not a demo deployment')
            ->assertSuccessful();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'deleted_at' => null]);
    }

    public function test_it_refuses_when_the_demo_slug_is_the_install_slug(): void
    {
        // A .env typo must not be able to point the deletion at the install
        // tenant, which owns the platform admin.
        Config::set('app.demo_tenant_slug', 'default');

        $install = Tenant::factory()->create(['slug' => 'default']);

        $this->artisan('demo:refresh', ['--force' => true])
            ->expectsOutputToContain('The install tenant is not demo data')
            ->assertFailed();

        $this->assertDatabaseHas('tenants', ['id' => $install->id, 'deleted_at' => null]);
    }

    public function test_it_refuses_when_the_demo_slug_is_empty(): void
    {
        Config::set('app.demo_tenant_slug', '');

        $this->artisan('demo:refresh', ['--force' => true])
            ->expectsOutputToContain('DEMO_TENANT_SLUG is empty')
            ->assertFailed();
    }

    public function test_dry_run_targets_only_the_demo_and_legacy_tenants(): void
    {
        $demo = Tenant::factory()->create(['slug' => 'demo-restaurant', 'name' => 'Demo Restaurant']);
        $legacy = Tenant::factory()->create(['slug' => 'legacy-restaurant', 'name' => 'Legacy Restaurant']);
        $customer = Tenant::factory()->create(['slug' => 'paying-customer', 'name' => 'Paying Customer']);
        $install = Tenant::factory()->create(['slug' => 'default', 'name' => 'Install Tenant']);

        $this->artisan('demo:refresh', ['--dry-run' => true])
            ->expectsOutputToContain('Demo Restaurant')
            ->expectsOutputToContain('Legacy Restaurant')
            ->expectsOutputToContain('Paying Customer')
            ->assertSuccessful();

        foreach ([$demo, $legacy, $customer, $install] as $tenant) {
            $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'deleted_at' => null]);
        }
    }

    public function test_the_confirmation_defaults_to_no(): void
    {
        // Non-interactive callers that forget --force must abort rather than
        // destroy. This is why cron and CI pass --force explicitly.
        $demo = Tenant::factory()->create(['slug' => 'demo-restaurant']);

        $this->artisan('demo:refresh', ['--skip-baseline' => true])
            ->expectsConfirmation('Destroy and rebuild the demo tenant [demo-restaurant]?', 'no')
            ->expectsOutputToContain('Aborted.')
            ->assertSuccessful();

        $this->assertDatabaseHas('tenants', ['id' => $demo->id, 'deleted_at' => null]);
    }
}
