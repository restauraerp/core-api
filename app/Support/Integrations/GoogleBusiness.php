<?php

namespace App\Support\Integrations;

use App\Models\GoogleBusinessConnection;
use App\Models\GoogleReview;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Google Business Profile, for a restaurant's reviews and rating.
 *
 * OAuth 2.0 with the business.manage scope, through RestoraERP's own Google
 * Cloud project (config('services.google_business')). That project must have
 * the Business Profile APIs enabled - Google grants access on request - and
 * its OAuth consent screen verified for the scope, or only test users can
 * connect.
 *
 * Reviews are read from the v4 API (the only one that has them), locations
 * from the Account Management and Business Information v1 APIs.
 */
final class GoogleBusiness
{
    public const PROVIDER = 'google_business';

    private const SCOPE = 'https://www.googleapis.com/auth/business.manage openid email';

    private const STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    /** Most reviews read per sync: newest first, so this is the recent ones. */
    private const MAX_REVIEWS = 200;

    public static function configured(): bool
    {
        return (bool) (config('services.google_business.client_id') && config('services.google_business.client_secret'));
    }

    public static function authorizeUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google_business.client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            // A refresh token, so reviews can be read later without the owner.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public static function redirectUri(): string
    {
        return (string) (config('services.google_business.redirect') ?: rtrim((string) config('platform.app_url'), '/').'/api/v1/integrations/google/callback');
    }

    /** @return array{refresh_token: ?string, access_token: string, expires_in: int, email: ?string} */
    public static function exchangeCode(string $code): array
    {
        $token = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('services.google_business.client_id'),
            'client_secret' => config('services.google_business.client_secret'),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ])->throw()->json();

        // The id_token is straight from Google over TLS; its payload is only
        // read for the email to show which account is connected.
        $email = null;
        if (isset($token['id_token']) && count($parts = explode('.', $token['id_token'])) === 3) {
            $email = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true)['email'] ?? null;
        }

        return [
            'refresh_token' => $token['refresh_token'] ?? null,
            'access_token' => $token['access_token'],
            'expires_in' => (int) ($token['expires_in'] ?? 3600),
            'email' => $email,
        ];
    }

    public function __construct(private GoogleBusinessConnection $connection) {}

    private function token(): string
    {
        $c = $this->connection;

        if ($c->access_token && $c->access_token_expires_at?->isAfter(now()->addMinute())) {
            return $c->access_token;
        }

        $token = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google_business.client_id'),
            'client_secret' => config('services.google_business.client_secret'),
            'refresh_token' => $c->refresh_token,
            'grant_type' => 'refresh_token',
        ])->throw()->json();

        $c->forceFill([
            'access_token' => $token['access_token'],
            'access_token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
        ])->save();

        return $token['access_token'];
    }

    private function get(string $url, array $query = []): array
    {
        return Http::withToken($this->token())->timeout(20)->get($url, $query)->throw()->json() ?? [];
    }

    /**
     * Every location the connected Google account manages.
     *
     * @return list<array{location: string, title: string, address: string}>
     */
    public function locations(): array
    {
        $out = [];
        $accounts = $this->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts')['accounts'] ?? [];

        foreach ($accounts as $account) {
            $page = null;
            do {
                $res = $this->get("https://mybusinessbusinessinformation.googleapis.com/v1/{$account['name']}/locations", array_filter([
                    'readMask' => 'name,title,storefrontAddress,metadata',
                    'pageSize' => 100,
                    'pageToken' => $page,
                ]));
                foreach ($res['locations'] ?? [] as $loc) {
                    $out[] = [
                        // The reviews API wants accounts/{a}/locations/{l}.
                        'location' => $account['name'].'/'.$loc['name'],
                        'title' => $loc['title'] ?? $loc['name'],
                        'address' => implode(', ', array_filter([
                            implode(' ', $loc['storefrontAddress']['addressLines'] ?? []),
                            $loc['storefrontAddress']['locality'] ?? null,
                        ])),
                        'maps_uri' => $loc['metadata']['mapsUri'] ?? null,
                        'review_uri' => $loc['metadata']['newReviewUri'] ?? null,
                    ];
                }
                $page = $res['nextPageToken'] ?? null;
            } while ($page);
        }

        return $out;
    }

    /**
     * Brings the chosen location's reviews and rating in. Runs in the
     * restaurant's tenant context.
     *
     * @return int reviews read
     */
    public function sync(): int
    {
        $c = $this->connection;
        if (! $c->location) {
            return 0;
        }

        try {
            $read = 0;
            $page = null;
            $summary = [];

            do {
                $res = $this->get("https://mybusiness.googleapis.com/v4/{$c->location}/reviews", array_filter([
                    'pageSize' => 50,
                    'orderBy' => 'updateTime desc',
                    'pageToken' => $page,
                ]));
                $summary = $summary ?: $res;

                foreach ($res['reviews'] ?? [] as $review) {
                    $this->store($review);
                    $read++;
                }
                $page = $res['nextPageToken'] ?? null;
            } while ($page && $read < self::MAX_REVIEWS);

            $c->forceFill([
                'average_rating' => isset($summary['averageRating']) ? round((float) $summary['averageRating'], 2) : null,
                'total_reviews' => isset($summary['totalReviewCount']) ? (int) $summary['totalReviewCount'] : null,
                'synced_at' => now(),
                'last_error' => null,
            ])->save();

            return $read;
        } catch (RequestException $e) {
            $c->forceFill(['last_error' => mb_substr($e->response->json('error.message') ?? $e->getMessage(), 0, 500)])->save();
            throw $e;
        }
    }

    private function store(array $review): void
    {
        $id = $review['reviewId'] ?? null;
        if (! $id) {
            return;
        }

        $existing = GoogleReview::where('external_id', $id)->first();
        $fields = [
            'source' => 'google',
            'author_name' => mb_substr($review['reviewer']['displayName'] ?? 'Google user', 0, 191),
            'author_photo_url' => preg_match('#^https://#', $review['reviewer']['profilePhotoUrl'] ?? '') ? mb_substr($review['reviewer']['profilePhotoUrl'], 0, 500) : null,
            'rating' => self::STARS[$review['starRating'] ?? ''] ?? null,
            // Google adds "(Translated by Google)" blocks; keep the original.
            'text' => isset($review['comment']) ? trim(preg_split('/\n\n\(Translated by Google\)/', $review['comment'])[0]) : null,
            'time' => isset($review['createTime']) ? Carbon::parse($review['createTime'])->setTimezone(config('app.timezone')) : null,
        ];

        if ($existing) {
            // The owner's choice to hide or show a review survives a sync.
            $existing->update($fields);
        } else {
            GoogleReview::create($fields + ['external_id' => $id, 'is_displayed' => true]);
        }
    }
}
