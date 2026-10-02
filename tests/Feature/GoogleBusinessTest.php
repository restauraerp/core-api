<?php

namespace Tests\Feature;

use App\Models\GoogleBusinessConnection;
use App\Models\GoogleReview;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Integrations\GoogleBusiness;
use App\Support\Integrations\OAuthState;
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

/** A restaurant's Google Business Profile: connect, pick the location, reviews and rating synced. */
class GoogleBusinessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    /** What Google answers for reviews; tests change it. */
    private array $reviews = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_business.client_id' => 'client-id',
            'services.google_business.client_secret' => 'client-secret',
            'platform.app_url' => 'https://app.restauraerp.test',
        ]);

        $this->reviews = [
            'averageRating' => 4.6,
            'totalReviewCount' => 312,
            'reviews' => [
                ['reviewId' => 'r1', 'reviewer' => ['displayName' => 'Rina', 'profilePhotoUrl' => 'https://lh3.googleusercontent.com/a'], 'starRating' => 'FIVE', 'comment' => 'Best kacchi in town', 'createTime' => '2026-09-30T10:00:00Z'],
                ['reviewId' => 'r2', 'reviewer' => ['displayName' => 'Karim', 'profilePhotoUrl' => 'javascript:alert(1)'], 'starRating' => 'TWO', 'comment' => "Slow\n\n(Translated by Google)\nSlow service", 'createTime' => '2026-09-29T10:00:00Z'],
            ],
        ];

        $idToken = 'x.'.rtrim(strtr(base64_encode(json_encode(['email' => 'owner@spice.test'])), '+/', '-_'), '=').'.y';

        Http::fake(function (HttpRequest $request) use ($idToken) {
            $url = $request->url();

            return match (true) {
                str_starts_with($url, 'https://oauth2.googleapis.com/token') => Http::response([
                    'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600, 'id_token' => $idToken,
                ]),
                str_contains($url, 'mybusinessaccountmanagement.googleapis.com/v1/accounts') => Http::response(['accounts' => [['name' => 'accounts/111']]]),
                str_contains($url, 'mybusinessbusinessinformation.googleapis.com/v1/accounts/111/locations') => Http::response(['locations' => [[
                    'name' => 'locations/222', 'title' => 'Spice Garden Dhanmondi',
                    'storefrontAddress' => ['addressLines' => ['Road 27'], 'locality' => 'Dhaka'],
                    'metadata' => ['mapsUri' => 'https://maps.google.com/?cid=1', 'newReviewUri' => 'https://g.page/r/abc/review'],
                ]]]),
                str_contains($url, 'mybusiness.googleapis.com/v4/accounts/111/locations/222/reviews') => Http::response($this->reviews),
                default => Http::response([], 404),
            };
        });

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = app(TenantProvisioner::class)->create([
            'name' => 'Spice Garden', 'slug' => 'spice', 'plan' => 'growth', 'status' => 'active',
        ]);
        $this->owner = $this->inTenant(function () {
            $user = User::factory()->create(['tenant_id' => $this->tenant->getKey()]);
            $user->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

            return $user;
        });
        Sanctum::actingAs($this->owner);
        $this->withHeader('X-Tenant-ID', 'spice');
    }

    private function inTenant(callable $work): mixed
    {
        return app(TenantContext::class)->runFor($this->tenant, $work);
    }

    private function connectAndPick(): void
    {
        $url = $this->postJson('/api/v1/site-builder/google/connect')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->get('/api/v1/integrations/google/callback?code=abc&state='.urlencode($query['state']))
            ->assertRedirect('https://app.restauraerp.test/admin/website/builder?google=connected');

        $this->putJson('/api/v1/site-builder/google/location', ['location' => 'accounts/111/locations/222'])->assertOk();
    }

    #[Test]
    public function connecting_asks_google_for_offline_access_to_the_profile(): void
    {
        $url = $this->postJson('/api/v1/site-builder/google/connect')->assertOk()->json('url');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertStringContainsString('business.manage', urldecode($url));
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString(urlencode('https://app.restauraerp.test/api/v1/integrations/google/callback'), $url);
    }

    #[Test]
    public function reviews_and_the_overall_rating_come_in(): void
    {
        $this->connectAndPick();

        $this->getJson('/api/v1/site-builder/google')
            ->assertJsonPath('connected', true)
            ->assertJsonPath('google_email', 'owner@spice.test')
            ->assertJsonPath('location_title', 'Spice Garden Dhanmondi')
            ->assertJsonPath('total_reviews', 312);

        [$rina, $karim] = $this->inTenant(fn () => [GoogleReview::where('external_id', 'r1')->sole(), GoogleReview::where('external_id', 'r2')->sole()]);
        $this->assertSame(5, $rina->rating);
        $this->assertSame('google', $rina->source);
        $this->assertTrue($rina->is_displayed);
        $this->assertSame('Slow', $karim->text);
        $this->assertNull($karim->author_photo_url);

        // Encrypted at rest.
        $this->assertStringNotContainsString('refresh', (string) \DB::table('google_business_connections')->value('refresh_token'));
    }

    #[Test]
    public function a_sync_updates_reviews_and_keeps_the_owners_choice_to_hide_one(): void
    {
        $this->connectAndPick();
        $this->inTenant(fn () => GoogleReview::where('external_id', 'r2')->update(['is_displayed' => false]));

        $this->reviews['reviews'][1]['starRating'] = 'FOUR';
        $this->postJson('/api/v1/site-builder/google/sync')->assertOk()->assertJsonPath('read', 2);

        $karim = $this->inTenant(fn () => GoogleReview::where('external_id', 'r2')->sole());
        $this->assertSame(4, $karim->rating);
        $this->assertFalse($karim->is_displayed);
        $this->assertSame(2, $this->inTenant(fn () => GoogleReview::count()));

        $this->artisan('site:sync-google-reviews')->assertSuccessful();
        $this->assertSame(2, $this->inTenant(fn () => GoogleReview::count()));
    }

    #[Test]
    public function the_website_shows_googles_rating_and_review_link(): void
    {
        $this->connectAndPick();
        $this->getJson('/api/v1/site-pages')->assertOk();
        $home = $this->getJson('/api/v1/site-pages')->json('pages.0.id');
        $this->putJson("/api/v1/site-pages/{$home}", ['sections' => [['type' => 'reviews', 'props' => ['min_rating' => '5', 'sort' => 'newest']]]])->assertOk();
        $this->postJson('/api/v1/site-builder/publish')->assertCreated();

        $this->getJson('/api/v1/site/spice')
            ->assertJsonPath('review_summary.rating', 4.6)
            ->assertJsonPath('review_summary.total', 312)
            ->assertJsonPath('review_summary.write_review_url', 'https://g.page/r/abc/review')
            ->assertJsonPath('reviews.0.author', 'Rina')
            ->assertJsonPath('reviews.0.time', fn ($t) => str_starts_with($t, '2026-09-30'))
            ->assertJsonPath('page.sections.0.props.min_rating', '5');
    }

    #[Test]
    public function a_forged_or_expired_callback_connects_nothing(): void
    {
        $this->get('/api/v1/integrations/google/callback?code=abc&state=forged')
            ->assertRedirect('https://app.restauraerp.test/admin/website/builder?google=failed');

        // A state for another integration is refused too.
        $meta = OAuthState::make('meta', $this->tenant->getKey(), $this->owner->getKey());
        $this->get('/api/v1/integrations/google/callback?code=abc&state='.urlencode($meta))
            ->assertRedirect('https://app.restauraerp.test/admin/website/builder?google=failed');

        $this->get('/api/v1/integrations/google/callback?error=access_denied&state='.urlencode(OAuthState::make(GoogleBusiness::PROVIDER, $this->tenant->getKey(), $this->owner->getKey())))
            ->assertRedirect('https://app.restauraerp.test/admin/website/builder?google=cancelled');

        $this->assertFalse(GoogleBusinessConnection::withoutGlobalScopes()->exists());
    }

    #[Test]
    public function only_a_profile_the_account_manages_can_be_chosen(): void
    {
        $this->connectAndPick();

        $this->putJson('/api/v1/site-builder/google/location', ['location' => 'accounts/111/locations/999'])
            ->assertUnprocessable()->assertJsonValidationErrors('location');
    }

    #[Test]
    public function without_platform_credentials_connecting_says_so(): void
    {
        config(['services.google_business.client_id' => null]);

        $this->getJson('/api/v1/site-builder/google')->assertJsonPath('available', false);
        $this->postJson('/api/v1/site-builder/google/connect')->assertUnprocessable()->assertJsonValidationErrors('google');
    }
}
