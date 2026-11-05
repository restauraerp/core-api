<?php

namespace App\Http\Controllers;

use App\Models\MetaConnection;
use App\Models\SocialPost;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Integrations\MetaPages;
use App\Support\Integrations\OAuthState;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a restaurant's Facebook Page (and the Instagram business
 * account linked to it), so their latest posts can show on its website.
 *
 * connect -> Facebook's dialog -> callback (public; the sealed state says for
 * whom) -> the owner picks the Page -> posts sync now and every hour.
 */
class MetaPagesController extends Controller
{
    public function __construct(private TenantContext $context) {}

    /** GET /site-builder/meta */
    public function show(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json($this->present(MetaConnection::first()) + [
            // For the editor's preview of the post sections.
            'posts' => ['facebook' => SocialPost::latest(SocialPost::FACEBOOK), 'instagram' => SocialPost::latest(SocialPost::INSTAGRAM)],
            'accounts' => MetaConnection::accountLinks(),
        ]);
    }

    /** POST /site-builder/meta/connect */
    public function connect(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        if (! MetaPages::configured()) {
            throw ValidationException::withMessages(['meta' => ['Facebook sign-in is not set up on this server yet. Ask RestoraERP support.']]);
        }

        $state = OAuthState::make(MetaPages::PROVIDER, (int) $this->context->id(), (int) $request->user()->getKey());

        return response()->json(['url' => MetaPages::authorizeUrl($state)]);
    }

    /** GET /integrations/meta/callback - public; Facebook returns the owner here. */
    public function callback(Request $request): RedirectResponse
    {
        $back = fn (string $result) => redirect()->away(rtrim((string) config('platform.app_url'), '/').'/admin/website/builder?meta='.$result);

        $who = OAuthState::read($request->query('state'), MetaPages::PROVIDER);
        $tenant = $who ? Tenant::find($who['tenant_id']) : null;
        if ($tenant === null || ! $request->filled('code')) {
            return $back($request->filled('error') ? 'cancelled' : 'failed');
        }

        try {
            $token = MetaPages::exchangeCode((string) $request->query('code'));
        } catch (RequestException) {
            return $back('failed');
        }

        return $this->context->runFor($tenant, function () use ($token, $who, $back) {
            if (! User::find($who['user_id'])?->can('manage_website_content')) {
                return $back('failed');
            }

            $connection = MetaConnection::first() ?? new MetaConnection;
            $connection->forceFill([
                'user_token' => $token['token'],
                'user_token_expires_at' => $token['expires_at'],
                'user_name' => $token['name'],
                'last_error' => null,
            ])->save();

            return $back('connected');
        });
    }

    /** GET /site-builder/meta/pages - the Pages the signed-in person manages. */
    public function pages(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json(['pages' => array_map(
            // The page tokens stay on the server.
            fn (array $p) => array_diff_key($p, ['token' => true]),
            $this->client()->pages(),
        )]);
    }

    /** PUT /site-builder/meta/page - the Page (and its Instagram) to show; syncs straight away. */
    public function choosePage(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate(['page_id' => ['required', 'string', 'regex:/^[0-9]{1,30}$/']]);

        $page = collect($this->client()->pages())->firstWhere('id', $validated['page_id'])
            ?? throw ValidationException::withMessages(['page_id' => ['That is not a Page you manage.']]);

        $connection = MetaConnection::firstOrFail();
        if ($connection->page_id !== $page['id']) {
            MetaPages::forget();
        }

        $connection->forceFill([
            'page_id' => $page['id'],
            'page_name' => $page['name'],
            'page_token' => $page['token'],
            'instagram_id' => $page['instagram']['id'] ?? null,
            // It becomes part of a link on the site: Instagram's own shape only.
            'instagram_username' => preg_match('/^[A-Za-z0-9._]{1,30}$/', (string) ($page['instagram']['username'] ?? '')) ? $page['instagram']['username'] : null,
        ])->save();

        return $this->sync($request);
    }

    /** POST /site-builder/meta/sync */
    public function sync(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        try {
            $read = $this->client()->sync();
        } catch (RequestException) {
            throw ValidationException::withMessages(['meta' => ['Facebook did not answer: '.(MetaConnection::first()?->last_error ?? 'unknown error')]]);
        }

        return response()->json(['read' => $read] + $this->present(MetaConnection::first()));
    }

    /** DELETE /site-builder/meta - disconnects, and the stored posts go with it. */
    public function disconnect(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        MetaConnection::query()->delete();
        MetaPages::forget();

        return response()->json($this->present(null));
    }

    private function client(): MetaPages
    {
        return new MetaPages(MetaConnection::first()
            ?? throw ValidationException::withMessages(['meta' => ['Connect Facebook first.']]));
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can('manage_website_content')) {
            abort(403, 'You do not have permission to edit the website.');
        }
    }

    /** @return array<string, mixed> */
    private function present(?MetaConnection $c): array
    {
        return [
            'available' => MetaPages::configured(),
            'connected' => $c !== null,
            'user_name' => $c?->user_name,
            'page_id' => $c?->page_id,
            'page_name' => $c?->page_name,
            'instagram_username' => $c?->instagram_username,
            'synced_at' => $c?->synced_at?->toIso8601String(),
            'last_error' => $c?->last_error,
        ];
    }
}
