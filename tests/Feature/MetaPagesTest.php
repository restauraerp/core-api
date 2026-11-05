<?php

namespace Tests\Feature;

use App\Models\MetaConnection;
use App\Models\SocialPost;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\RoleDefinitions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** A restaurant's Facebook Page and Instagram account: connect, pick the Page, posts on the website. */
class MetaPagesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    /** The Page's posts as Facebook returns them; tests change it. */
    private array $facebook = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.app_id' => 'app-id',
            'services.meta.app_secret' => 'app-secret',
            'platform.app_url' => 'https://app.restauraerp.test',
        ]);

        $this->facebook = [
            ['id' => '10_1', 'message' => 'Kacchi Friday is back!', 'full_picture' => 'https://scontent.xx.fbcdn.net/a.jpg', 'permalink_url' => 'https://www.facebook.com/10/posts/1', 'created_time' => '2026-10-01T08:00:00+0000'],
            ['id' => '10_2', 'message' => 'Closed for Eid', 'permalink_url' => 'https://www.facebook.com/10/posts/2', 'created_time' => '2026-09-20T08:00:00+0000'],
            ['id' => '10_3', 'message' => 'Sneaky', 'full_picture' => 'javascript:alert(1)', 'permalink_url' => 'javascript:alert(2)', 'created_time' => '2026-09-10T08:00:00+0000'],
        ];

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/oauth/access_token') => Http::response(['access_token' => 'user-token', 'expires_in' => 5184000]),
                str_contains($url, '/me/accounts') => Http::response(['data' => [[
                    'id' => '10', 'name' => 'Spice Garden', 'access_token' => 'page-token',
                    'instagram_business_account' => ['id' => '20', 'username' => 'spice.garden'],
                ]]]),
                str_contains($url, '/me?') || str_ends_with(parse_url($url, PHP_URL_PATH), '/me') => Http::response(['name' => 'Owner Name']),
                str_contains($url, '/10/published_posts') => Http::response(['data' => $this->facebook]),
                str_contains($url, '/20/media') => Http::response(['data' => [
                    ['id' => 'ig1', 'caption' => 'Fresh naan', 'media_type' => 'IMAGE', 'media_url' => 'https://scontent.cdninstagram.com/1.jpg', 'permalink' => 'https://www.instagram.com/p/1/', 'timestamp' => '2026-10-01T09:00:00+0000'],
                    ['id' => 'ig2', 'caption' => 'Behind the tandoor', 'media_type' => 'VIDEO', 'media_url' => 'https://scontent.cdninstagram.com/2.mp4', 'thumbnail_url' => 'https://scontent.cdninstagram.com/2.jpg', 'permalink' => 'https://www.instagram.com/reel/2/', 'timestamp' => '2026-09-30T09:00:00+0000'],
                ]]),
                default => Http::response([], 404),
            };
        });

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Spice Garden', 'slug' => 'spice', 'plan' => 'growth', 'status' => 'active',
        ]);
        Sanctum::actingAs(app(TenantContext::class)->runFor($this->tenant, function () {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $user->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

            return $user;
        }));
        $this->withHeader('X-Tenant-ID', 'spice');
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function connectAndPick(): void
    {
        $url = $this->postJson('/api/v1/site-builder/meta/connect')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringContainsString('pages_read_engagement', $query['scope']);
        $this->assertStringContainsString('instagram_basic', $query['scope']);

        $this->get('/api/v1/integrations/meta/callback?code=abc&state='.urlencode($query['state']))
            ->assertRedirect('https://app.restauraerp.test/admin/website/builder?meta=connected');

        $this->getJson('/api/v1/site-builder/meta/pages')
            ->assertOk()
            ->assertJsonPath('pages.0.instagram.username', 'spice.garden')
            // Page tokens never leave the server.
            ->assertJsonMissingPath('pages.0.token');

        $this->putJson('/api/v1/site-builder/meta/page', ['page_id' => '10'])
            ->assertOk()
            ->assertJsonPath('read.facebook', 3)
            ->assertJsonPath('read.instagram', 2)
            ->assertJsonPath('page_name', 'Spice Garden');
    }

    #[Test]
    public function the_page_and_instagram_posts_come_in(): void
    {
        $this->connectAndPick();

        $posts = $this->inTenant(fn () => SocialPost::orderBy('id')->get());
        $this->assertCount(5, $posts);
        $reel = $posts->firstWhere('external_id', 'ig2');
        $this->assertSame('https://scontent.cdninstagram.com/2.jpg', $reel->image_url);

        // Anything that is not a web link is dropped, not stored.
        $sneaky = $posts->firstWhere('external_id', '10_3');
        $this->assertNull($sneaky->image_url);
        $this->assertNull($sneaky->permalink);

        $this->assertStringNotContainsString('page-token', (string) \DB::table('meta_connections')->value('page_token'));
    }

    #[Test]
    public function a_deleted_post_disappears_on_the_next_sync(): void
    {
        $this->connectAndPick();
        array_pop($this->facebook);

        $this->artisan('site:sync-social-posts')->assertSuccessful();

        $this->assertSame(2, $this->inTenant(fn () => SocialPost::where('network', 'facebook')->count()));
    }

    #[Test]
    public function the_website_shows_the_posts_with_follow_links(): void
    {
        $this->connectAndPick();
        $home = $this->getJson('/api/v1/site-pages')->json('pages.0.id');
        $this->putJson("/api/v1/site-pages/{$home}", ['sections' => [
            ['type' => 'facebook_posts', 'props' => ['count' => '6']],
            ['type' => 'instagram_feed'],
        ]])->assertOk();
        $this->postJson('/api/v1/site-builder/publish')->assertCreated();

        $this->getJson('/api/v1/site/spice')
            ->assertJsonPath('posts.facebook.0.text', 'Kacchi Friday is back!')
            ->assertJsonPath('posts.instagram.1.image', 'https://scontent.cdninstagram.com/2.jpg')
            ->assertJsonPath('social_accounts.facebook.url', 'https://www.facebook.com/10')
            ->assertJsonPath('social_accounts.instagram.url', 'https://www.instagram.com/spice.garden/');
    }

    #[Test]
    public function only_a_page_the_person_manages_can_be_chosen_and_disconnecting_clears_posts(): void
    {
        $this->connectAndPick();

        $this->putJson('/api/v1/site-builder/meta/page', ['page_id' => '999'])->assertUnprocessable()->assertJsonValidationErrors('page_id');

        $this->deleteJson('/api/v1/site-builder/meta')->assertOk()->assertJsonPath('connected', false);
        $this->assertSame(0, $this->inTenant(fn () => SocialPost::count()));
        $this->assertFalse(MetaConnection::withoutGlobalScopes()->exists());
    }

    #[Test]
    public function a_forged_callback_connects_nothing(): void
    {
        $this->get('/api/v1/integrations/meta/callback?code=abc&state=nope')
            ->assertRedirect('https://app.restauraerp.test/admin/website/builder?meta=failed');

        $this->assertFalse(MetaConnection::withoutGlobalScopes()->exists());
    }
}
