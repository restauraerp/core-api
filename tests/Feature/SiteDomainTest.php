<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Site\DnsLookup;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where a restaurant's site is reached: {code}.{base}, and optionally its own
 * domain once DNS is verified - and the checks the front (resolve) and Caddy
 * (allowed) rely on to serve and certify only real restaurant sites.
 */
class SiteDomainTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    /** @var list<string> */
    private array $cnames = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.base_domain' => 'restauraerp.com', 'site.cname_target' => 'sites.restauraerp.com', 'site.server_ip' => '203.0.113.10']);
        $this->seed(RolePermissionSeeder::class);

        $this->app->instance(DnsLookup::class, new class($this) extends DnsLookup
        {
            public function __construct(private SiteDomainTest $test) {}

            public function cnames(string $host): array
            {
                return $this->test->fakeCnames();
            }

            public function addresses(string $host): array
            {
                return [];
            }
        });

        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Spice Garden', 'slug' => 'spice', 'plan' => 'growth', 'status' => 'active',
        ]);

        $owner = app(TenantContext::class)->runFor($this->tenant, function () {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $user->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

            return $user;
        });

        Sanctum::actingAs($owner);
        $this->withHeader('X-Tenant-ID', 'spice');
    }

    /** @return list<string> */
    public function fakeCnames(): array
    {
        return $this->cnames;
    }

    #[Test]
    public function every_site_has_its_subdomain(): void
    {
        $this->getJson('/api/v1/site-builder/domain')
            ->assertOk()
            ->assertJsonPath('subdomain', 'spice.restauraerp.com')
            ->assertJsonPath('domain', null);

        $this->get('/api/v1/site-domains/allowed?domain=spice.restauraerp.com')->assertOk();
        $this->get('/api/v1/site-domains/allowed?domain=nobody.restauraerp.com')->assertNotFound();
        $this->get('/api/v1/site-domains/allowed?domain=a.spice.restauraerp.com')->assertNotFound();
    }

    #[Test]
    public function a_custom_domain_is_served_only_once_verified(): void
    {
        $this->putJson('/api/v1/site-builder/domain', ['domain' => 'https://BanglaBistro.com/menu'])
            ->assertOk()
            ->assertJsonPath('domain', 'banglabistro.com')
            ->assertJsonPath('verified', false);

        // Not pointed at us yet: no certificate, not served.
        $this->get('/api/v1/site-domains/allowed?domain=banglabistro.com')->assertNotFound();
        $this->getJson('/api/v1/site-domains/resolve?host=banglabistro.com')->assertNotFound();
        $this->postJson('/api/v1/site-builder/domain/verify')->assertUnprocessable()->assertJsonValidationErrors('domain');

        $this->cnames = ['sites.restauraerp.com'];
        $this->postJson('/api/v1/site-builder/domain/verify')->assertOk()->assertJsonPath('verified', true);

        $this->get('/api/v1/site-domains/allowed?domain=banglabistro.com')->assertOk();
        $this->getJson('/api/v1/site-domains/resolve?host=banglabistro.com')->assertOk()->assertJsonPath('code', 'spice');

        // Changing the domain starts verification over.
        $this->putJson('/api/v1/site-builder/domain', ['domain' => 'spicegarden.com.bd'])->assertJsonPath('verified', false);
        $this->getJson('/api/v1/site-domains/resolve?host=banglabistro.com')->assertNotFound();
    }

    #[Test]
    public function our_own_and_other_restaurants_domains_are_refused(): void
    {
        $this->putJson('/api/v1/site-builder/domain', ['domain' => 'shop.restauraerp.com'])->assertUnprocessable();
        $this->putJson('/api/v1/site-builder/domain', ['domain' => 'not a domain'])->assertUnprocessable();

        Tenant::factory()->create(['domain' => 'taken.com']);
        $this->putJson('/api/v1/site-builder/domain', ['domain' => 'taken.com'])->assertUnprocessable();

        $this->putJson('/api/v1/site-builder/domain', ['domain' => null])->assertOk()->assertJsonPath('domain', null);
    }

    #[Test]
    public function without_the_website_module_nothing_is_served(): void
    {
        $this->tenant->forceFill(['domain' => 'spice.com', 'domain_verified_at' => now(), 'plan' => 'starter'])->save();

        $this->get('/api/v1/site-domains/allowed?domain=spice.restauraerp.com')->assertNotFound();
        $this->get('/api/v1/site-domains/allowed?domain=spice.com')->assertNotFound();
        $this->getJson('/api/v1/site-domains/resolve?host=spice.com')->assertNotFound();
    }

    #[Test]
    public function templates_and_site_settings(): void
    {
        $this->getJson('/api/v1/site-builder/templates')
            ->assertOk()
            ->assertJsonPath('templates.classic.sections.0.type', 'hero')
            ->assertJsonPath('templates.classic.sections.0.props.title', 'Spice Garden')
            ->assertJsonPath('templates.fine_dining.sections.3.type', 'reservations');

        $this->putJson('/api/v1/site-builder/theme', [
            'palette' => 'ocean', 'font' => 'classic',
            'header_order_button' => false, 'footer_social' => true, 'footer_text' => 'Open daily 11am - 11pm',
        ])->assertOk()
            ->assertJsonPath('theme.header_order_button', false)
            ->assertJsonPath('theme.footer_text', 'Open daily 11am - 11pm');

        $this->putJson('/api/v1/site-builder/theme', ['palette' => 'ocean', 'font' => 'classic', 'footer_text' => '<b>hi</b>'])
            ->assertUnprocessable()->assertJsonValidationErrors('footer_text');

        // Site settings are part of the draft: live on Publish website.
        $this->getJson('/api/v1/site/spice')->assertJsonPath('theme.footer_text', '');
        $this->getJson('/api/v1/site-pages')->assertOk();
        $this->postJson('/api/v1/site-builder/publish')->assertCreated();
        $this->getJson('/api/v1/site/spice')->assertJsonPath('theme.footer_text', 'Open daily 11am - 11pm');
    }

    #[Test]
    public function templates_leave_out_what_the_package_does_not_include(): void
    {
        $package = Package::create(['name' => 'No CRM', 'modules' => ['pos', 'orders', 'website'], 'outlets' => 1]);
        $this->tenant->update(['package_id' => $package->id]);

        $types = array_column($this->getJson('/api/v1/site-builder/templates')->json('templates.fine_dining.sections'), 'type');
        $this->assertNotContains('reservations', $types);
    }
}
