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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Branches, website settings and website content were whole apiResources
 * outside the signed-in group: anyone who knew a restaurant code could add,
 * change or delete branches, tables, settings, pages, social links and
 * reviews. Reads stay public (the storefront and receipts need them); writes
 * now need a signed-in user with the right permission.
 */
class WebsiteContentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = app(TenantProvisioner::class)->create([
            // Enterprise: no outlet cap, so the owner's new branch is not refused for that.
            'name' => 'Locked', 'slug' => 'locked', 'plan' => 'enterprise', 'status' => 'active',
        ]);
        $this->withHeader('X-Tenant-ID', 'locked');
    }

    private function signIn(string $role): void
    {
        Sanctum::actingAs(app(TenantContext::class)->runFor($this->tenant, function () use ($role) {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $user->assignRole($role);

            return $user;
        }));
    }

    /** @return array<string, array{string, string, array<string, mixed>}> */
    public static function writes(): array
    {
        return [
            'create a branch' => ['POST', '/api/v1/locations', ['name' => 'Hijacked']],
            'add a table' => ['POST', '/api/v1/locations/{location}/tables', ['name' => 'T99']],
            'change a setting' => ['POST', '/api/v1/website-settings', ['key' => 'site_name', 'value' => 'Hijacked']],
            'add a page' => ['POST', '/api/v1/pages', ['slug' => 'x', 'title' => 'x']],
            'add a social link' => ['POST', '/api/v1/social-links', ['platform' => 'x', 'url' => 'https://x.test']],
            'add a review' => ['POST', '/api/v1/google-reviews', ['author_name' => 'x', 'rating' => 5]],
        ];
    }

    private function path(string $path): string
    {
        $location = app(TenantContext::class)->runFor($this->tenant, fn () => Location::query()->value('id'));

        return str_replace('{location}', (string) $location, $path);
    }

    #[Test]
    #[DataProvider('writes')]
    public function nobody_signed_out_can_write(string $method, string $path, array $body): void
    {
        $this->json($method, $this->path($path), $body)->assertUnauthorized();
    }

    #[Test]
    #[DataProvider('writes')]
    public function staff_without_the_permission_cannot_write(string $method, string $path, array $body): void
    {
        $this->signIn('chef');

        $this->json($method, $this->path($path), $body)->assertForbidden();
    }

    #[Test]
    #[DataProvider('writes')]
    public function the_owner_can_write(string $method, string $path, array $body): void
    {
        $this->signIn(RoleDefinitions::RESTAURANT_ADMIN);

        $status = $this->json($method, $this->path($path), $body)->status();
        $this->assertContains($status, [200, 201, 422], "Owner was refused with {$status}.");
    }

    #[Test]
    public function public_reads_still_work(): void
    {
        foreach (['/api/v1/locations', '/api/v1/website-settings', '/api/v1/pages', '/api/v1/social-links', '/api/v1/google-reviews'] as $path) {
            $this->getJson($path)->assertOk();
        }
    }

    #[Test]
    public function page_html_is_cleaned_on_save(): void
    {
        $this->signIn(RoleDefinitions::RESTAURANT_ADMIN);

        $content = $this->postJson('/api/v1/pages', [
            'slug' => 'our-kitchen', 'title' => 'Our kitchen',
            'content' => '<h2>Hello</h2><p onclick="steal()">Hi <strong>there</strong></p><script>alert(1)</script><a href="javascript:alert(1)">x</a><img src="x" onerror="steal()">',
        ])->assertCreated()->json('content');

        $this->assertStringContainsString('<h2>Hello</h2>', $content);
        $this->assertStringContainsString('<strong>there</strong>', $content);
        $this->assertStringNotContainsString('<script', $content);
        $this->assertStringNotContainsString('onclick', $content);
        $this->assertStringNotContainsString('onerror', $content);
        $this->assertStringNotContainsString('javascript:', $content);
    }

    #[Test]
    public function social_links_must_be_web_addresses(): void
    {
        $this->signIn(RoleDefinitions::RESTAURANT_ADMIN);

        $this->postJson('/api/v1/social-links', ['platform' => 'x', 'url' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->postJson('/api/v1/social-links', ['platform' => 'Facebook', 'url' => 'https://facebook.com/locked'])->assertCreated();
    }
}
