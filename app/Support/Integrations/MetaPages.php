<?php

namespace App\Support\Integrations;

use App\Models\MetaConnection;
use App\Models\SocialPost;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * A restaurant's Facebook Page and its linked Instagram business account,
 * for showing their latest posts on its website.
 *
 * Facebook Login for Business through RestoraERP's own Meta app
 * (config('services.meta')). Meta must approve the app (App Review) for
 * pages_show_list, pages_read_engagement, instagram_basic and
 * business_management before other businesses can connect; until then only
 * the app's own testers can.
 *
 * Instagram is read through the Page: a business or creator account linked
 * to it in Meta Business Suite. A personal Instagram account cannot be read.
 */
final class MetaPages
{
    public const PROVIDER = 'meta';

    private const SCOPES = 'pages_show_list,pages_read_engagement,instagram_basic,business_management';

    /** Posts kept per network - more than any section shows. */
    private const KEEP = 24;

    public static function configured(): bool
    {
        return (bool) (config('services.meta.app_id') && config('services.meta.app_secret'));
    }

    private static function graph(string $path = ''): string
    {
        return 'https://graph.facebook.com/'.config('site.meta_graph').$path;
    }

    public static function redirectUri(): string
    {
        return (string) (config('services.meta.redirect') ?: rtrim((string) config('platform.app_url'), '/').'/api/v1/integrations/meta/callback');
    }

    public static function authorizeUrl(string $state): string
    {
        return 'https://www.facebook.com/'.config('site.meta_graph').'/dialog/oauth?'.http_build_query([
            'client_id' => config('services.meta.app_id'),
            'redirect_uri' => self::redirectUri(),
            'state' => $state,
            'scope' => self::SCOPES,
            'response_type' => 'code',
        ]);
    }

    /**
     * The code for a long-lived user token (about 60 days) - long enough to
     * pick a Page, whose own token, taken from it, does not expire.
     *
     * @return array{token: string, expires_at: ?Carbon, name: ?string}
     */
    public static function exchangeCode(string $code): array
    {
        $app = ['client_id' => config('services.meta.app_id'), 'client_secret' => config('services.meta.app_secret')];

        $short = Http::timeout(20)->get(self::graph('/oauth/access_token'), $app + [
            'redirect_uri' => self::redirectUri(), 'code' => $code,
        ])->throw()->json('access_token');

        $long = Http::timeout(20)->get(self::graph('/oauth/access_token'), $app + [
            'grant_type' => 'fb_exchange_token', 'fb_exchange_token' => $short,
        ])->throw()->json();

        $me = Http::timeout(20)->get(self::graph('/me'), ['fields' => 'name', 'access_token' => $long['access_token']])->json();

        return [
            'token' => $long['access_token'],
            'expires_at' => isset($long['expires_in']) ? now()->addSeconds((int) $long['expires_in']) : null,
            'name' => $me['name'] ?? null,
        ];
    }

    public function __construct(private MetaConnection $connection) {}

    /**
     * The Pages the signed-in person manages, each with its Instagram account if linked.
     *
     * @return list<array{id: string, name: string, picture: ?string, instagram: ?array{id: string, username: ?string}, token: string}>
     */
    public function pages(): array
    {
        $out = [];
        $url = self::graph('/me/accounts');
        $query = ['fields' => 'id,name,access_token,picture{url},instagram_business_account{id,username}', 'limit' => 100, 'access_token' => $this->connection->user_token];

        while ($url) {
            $res = Http::timeout(20)->get($url, $query)->throw()->json();
            foreach ($res['data'] ?? [] as $page) {
                $out[] = [
                    'id' => (string) $page['id'],
                    'name' => $page['name'] ?? '',
                    'picture' => $page['picture']['data']['url'] ?? null,
                    'instagram' => isset($page['instagram_business_account']['id'])
                        ? ['id' => (string) $page['instagram_business_account']['id'], 'username' => $page['instagram_business_account']['username'] ?? null]
                        : null,
                    'token' => $page['access_token'],
                ];
            }
            // The next link carries its own query.
            $url = $res['paging']['next'] ?? null;
            $query = [];
        }

        return $out;
    }

    /**
     * Brings the latest posts in, for the Page and its Instagram account.
     * Runs in the restaurant's tenant context.
     *
     * @return array{facebook: int, instagram: int}
     */
    public function sync(): array
    {
        $c = $this->connection;
        if (! $c->page_id || ! $c->page_token) {
            return ['facebook' => 0, 'instagram' => 0];
        }

        try {
            $facebook = Http::timeout(20)->get(self::graph("/{$c->page_id}/published_posts"), [
                'fields' => 'id,message,full_picture,permalink_url,created_time,status_type',
                'limit' => self::KEEP,
                'access_token' => $c->page_token,
            ])->throw()->json('data') ?? [];

            $this->store(SocialPost::FACEBOOK, array_map(fn ($p) => [
                'external_id' => (string) $p['id'],
                'text' => $p['message'] ?? null,
                'image_url' => $p['full_picture'] ?? null,
                'permalink' => $p['permalink_url'] ?? null,
                'media_type' => $p['status_type'] ?? null,
                'posted_at' => isset($p['created_time']) ? Carbon::parse($p['created_time'])->setTimezone(config('app.timezone')) : null,
            ], $facebook));

            $instagram = [];
            if ($c->instagram_id) {
                $instagram = Http::timeout(20)->get(self::graph("/{$c->instagram_id}/media"), [
                    'fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp',
                    'limit' => self::KEEP,
                    'access_token' => $c->page_token,
                ])->throw()->json('data') ?? [];

                $this->store(SocialPost::INSTAGRAM, array_map(fn ($p) => [
                    'external_id' => (string) $p['id'],
                    'text' => $p['caption'] ?? null,
                    // A video's picture is its thumbnail.
                    'image_url' => ($p['media_type'] ?? '') === 'VIDEO' ? ($p['thumbnail_url'] ?? null) : ($p['media_url'] ?? null),
                    'permalink' => $p['permalink'] ?? null,
                    'media_type' => $p['media_type'] ?? null,
                    'posted_at' => isset($p['timestamp']) ? Carbon::parse($p['timestamp'])->setTimezone(config('app.timezone')) : null,
                ], $instagram));
            }

            $c->forceFill(['synced_at' => now(), 'last_error' => null])->save();

            return ['facebook' => count($facebook), 'instagram' => count($instagram)];
        } catch (RequestException $e) {
            $c->forceFill(['last_error' => mb_substr($e->response->json('error.message') ?? $e->getMessage(), 0, 500)])->save();
            throw $e;
        }
    }

    /**
     * Replaces a network's stored posts with the latest: updated in place,
     * and a post deleted on Facebook or Instagram disappears here too.
     *
     * @param  list<array<string, mixed>>  $posts
     */
    private function store(string $network, array $posts): void
    {
        $keep = [];
        foreach ($posts as $post) {
            // Only web links, so a stored value can never become script on the site.
            foreach (['image_url', 'permalink'] as $link) {
                if (isset($post[$link]) && ! preg_match('#^https://#i', (string) $post[$link])) {
                    $post[$link] = null;
                }
            }
            SocialPost::updateOrCreate(['network' => $network, 'external_id' => $post['external_id']], $post);
            $keep[] = $post['external_id'];
        }

        SocialPost::where('network', $network)->whereNotIn('external_id', $keep)->delete();
    }

    /** Forgets the Page: its posts go too. */
    public static function forget(): void
    {
        SocialPost::query()->delete();
    }
}
