<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\Subscription;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * tenants:remove - the irreversible one.
 *
 * The cascading foreign keys are the database's job and are not re-tested here.
 * What is tested is everything the cascade cannot reach: the auth rows hanging
 * off users, the uploaded files, and the cached entitlement state - each of
 * which has to be swept by hand, and so can be forgotten.
 */
class RemoveTenantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    /** What the website answers the erase call with. */
    private int $websiteStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        // The command tells the marketing site to erase its own records; no
        // test here should actually reach out over the network.
        //
        // Resolved at request time rather than stubbed per status, because
        // Http::fake() merges stubs and the first match wins - a second fake
        // registered inside a test would never be reached.
        Http::fake(fn () => $this->websiteStatus === 200
            ? Http::response(['deleted' => ['subscription_orders' => 0, 'verification_sessions' => 0]])
            : Http::response('unavailable', $this->websiteStatus));

        config(['platform.website_url' => 'https://website.test', 'platform.token' => 'test-secret']);

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = Tenant::factory()->create(['slug' => 'removal-test', 'plan' => 'enterprise']);

        app(TenantContext::class)->runFor($this->tenant, function () {
            app(TenantProvisioner::class)->createRoles($this->tenant);

            $this->user = User::create([
                'tenant_id' => $this->tenant->getKey(),
                'name' => 'Owner',
                'email' => 'owner@removal-test.test',
                'password' => Hash::make('secret-password'),
            ]);
        });
    }

    private function remove(array $options = []): void
    {
        $this->artisan('tenants:remove', ['tenant' => 'removal-test', '--force' => true] + $options)
            ->assertSuccessful();
    }

    public function test_it_leaves_no_row_carrying_the_tenant_id(): void
    {
        $tenantId = $this->tenant->getKey();

        $this->remove();

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            $this->assertSame(
                0,
                DB::table($table)->where('tenant_id', $tenantId)->count(),
                "Rows carrying tenant_id {$tenantId} survived in [{$table}].",
            );
        }

        $this->assertSame(0, Tenant::withTrashed()->whereKey($tenantId)->count());
    }

    public function test_it_removes_the_sessions_and_api_tokens_of_its_users(): void
    {
        $this->user->createToken('phone');

        DB::table('sessions')->insert([
            'id' => 'removal-test-session',
            'user_id' => $this->user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'x',
            'last_activity' => time(),
        ]);

        $this->remove();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->getKey())->count());
        $this->assertSame(0, DB::table('personal_access_tokens')
            ->where('tokenable_id', $this->user->getKey())
            ->count());
    }

    public function test_it_deletes_the_uploaded_files_its_rows_point_at(): void
    {
        Storage::disk('public')->put('users/avatar.png', 'x');
        $this->user->forceFill(['image_url' => 'users/avatar.png'])->save();

        Storage::disk('public')->put('images/logo.png', 'x');
        DB::table('website_settings')->insert([
            'tenant_id' => $this->tenant->getKey(),
            'key' => 'logo_url',
            'value' => 'images/logo.png',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->remove();

        Storage::disk('public')->assertMissing('users/avatar.png');
        Storage::disk('public')->assertMissing('images/logo.png');
    }

    public function test_it_keeps_a_file_another_tenant_still_points_at(): void
    {
        $other = Tenant::factory()->create(['slug' => 'bystander']);

        Storage::disk('public')->put('foods/seeded.png', 'x');

        foreach ([$this->tenant, $other] as $owner) {
            DB::table('product_categories')->insert([
                'tenant_id' => $owner->getKey(),
                'name' => 'Shared',
                'image_url' => 'foods/seeded.png',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->remove();

        // Demo and seeded imagery is reused across tenants; deleting it here
        // would blank out a restaurant that had nothing to do with this.
        Storage::disk('public')->assertExists('foods/seeded.png');
    }

    public function test_keep_assets_leaves_the_files_alone(): void
    {
        Storage::disk('public')->put('users/avatar.png', 'x');
        $this->user->forceFill(['image_url' => 'users/avatar.png'])->save();

        $this->remove(['--keep-assets' => true]);

        Storage::disk('public')->assertExists('users/avatar.png');
    }

    public function test_it_forgets_the_cached_entitlement_state(): void
    {
        $tenantId = $this->tenant->getKey();

        $cache = Cache::store(config('billing.cache.store'));
        $key = config('billing.cache.prefix').":{$tenantId}";

        // Warm it the way a request would.
        Subscription::for($this->tenant);
        $this->assertTrue($cache->has($key), 'Precondition: the entitlement cache was never warmed.');

        $this->remove();

        // No foreign key reaches the cache store. Left behind, this is both a
        // leftover copy of the tenant's billing dates and a trap for whoever
        // ends up with the same id next.
        $this->assertFalse(
            $cache->has($key),
            'The purged tenant left its cached subscription state behind.',
        );
    }

    public function test_it_asks_the_website_to_erase_its_own_records(): void
    {
        // The subscription order and verification session from this
        // restaurant's signup live in the website's database, carrying the
        // owner's name, phone and bKash transaction id. Nothing else removes
        // them.
        $this->remove();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://website.test/api/tenants/removal-test/records');
    }

    public function test_it_warns_loudly_when_the_website_cannot_be_reached(): void
    {
        $this->websiteStatus = 500;

        $this->artisan('tenants:remove', ['tenant' => 'removal-test', '--force' => true])
            ->expectsOutputToContain('Could not reach the website')
            ->assertSuccessful();
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        Storage::disk('public')->put('users/avatar.png', 'x');
        $this->user->forceFill(['image_url' => 'users/avatar.png'])->save();

        $this->artisan('tenants:remove', ['tenant' => 'removal-test', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(1, Tenant::whereKey($this->tenant->getKey())->count());
        Storage::disk('public')->assertExists('users/avatar.png');
    }
}
