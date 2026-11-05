<?php

namespace App\Http\Controllers;

use App\Models\GoogleBusinessConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Integrations\GoogleBusiness;
use App\Support\Integrations\OAuthState;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a restaurant's Google Business Profile, so its reviews and
 * overall rating reach its website by themselves.
 *
 * connect -> Google's consent screen -> callback (public: Google sends the
 * browser back with no session, so who it is for travels in the sealed
 * state) -> the owner picks the location -> sync, now and every hour.
 */
class GoogleBusinessController extends Controller
{
    public function __construct(private TenantContext $context) {}

    /** GET /site-builder/google */
    public function show(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json($this->present(GoogleBusinessConnection::first()));
    }

    /** POST /site-builder/google/connect - where to send the owner to sign in with Google. */
    public function connect(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        if (! GoogleBusiness::configured()) {
            throw ValidationException::withMessages(['google' => ['Google sign-in is not set up on this server yet. Ask RestoraERP support.']]);
        }

        $state = OAuthState::make(GoogleBusiness::PROVIDER, (int) $this->context->id(), (int) $request->user()->getKey());

        return response()->json(['url' => GoogleBusiness::authorizeUrl($state)]);
    }

    /** GET /integrations/google/callback - public; Google returns the owner here. */
    public function callback(Request $request): RedirectResponse
    {
        $back = fn (string $result) => redirect()->away(rtrim((string) config('platform.app_url'), '/').'/admin/website/builder?google='.$result);

        $who = OAuthState::read($request->query('state'), GoogleBusiness::PROVIDER);
        $tenant = $who ? Tenant::find($who['tenant_id']) : null;
        if ($tenant === null || ! $request->filled('code')) {
            // Denied on Google's screen, expired, or not ours.
            return $back($request->query('error') === 'access_denied' ? 'cancelled' : 'failed');
        }

        try {
            $token = GoogleBusiness::exchangeCode((string) $request->query('code'));
        } catch (RequestException) {
            return $back('failed');
        }

        return $this->context->runFor($tenant, function () use ($token, $who, $back) {
            $user = User::find($who['user_id']);
            if (! $user?->can('manage_website_content')) {
                return $back('failed');
            }

            $existing = GoogleBusinessConnection::first();
            // Google only sends a refresh token on first consent; keep the old
            // one when it is the same account coming back.
            $refresh = $token['refresh_token'] ?? $existing?->refresh_token;
            if (! $refresh) {
                return $back('failed');
            }

            $sameAccount = $existing && $existing->google_email === $token['email'];
            $connection = $existing ?? new GoogleBusinessConnection;
            $connection->forceFill([
                'refresh_token' => $refresh,
                'access_token' => $token['access_token'],
                'access_token_expires_at' => now()->addSeconds($token['expires_in']),
                'google_email' => $token['email'],
                'last_error' => null,
            ] + ($sameAccount ? [] : ['location' => null, 'location_title' => null, 'maps_uri' => null, 'review_uri' => null]))->save();

            return $back('connected');
        });
    }

    /** GET /site-builder/google/locations - the profiles the connected account manages. */
    public function locations(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json(['locations' => $this->client()->locations()]);
    }

    /** PUT /site-builder/google/location - the profile whose reviews to show; syncs straight away. */
    public function chooseLocation(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate(['location' => ['required', 'string', 'max:191', 'regex:#^accounts/[0-9]+/locations/[0-9]+$#']]);

        $client = $this->client();
        $location = collect($client->locations())->firstWhere('location', $validated['location'])
            ?? throw ValidationException::withMessages(['location' => ['That profile is not one your Google account manages.']]);

        $connection = GoogleBusinessConnection::firstOrFail();
        $connection->forceFill([
            'location' => $location['location'],
            'location_title' => $location['title'],
            'maps_uri' => $location['maps_uri'],
            'review_uri' => $location['review_uri'],
        ])->save();

        return $this->sync($request);
    }

    /** POST /site-builder/google/sync */
    public function sync(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        try {
            $read = $this->client()->sync();
        } catch (RequestException) {
            throw ValidationException::withMessages(['google' => ['Google did not answer: '.(GoogleBusinessConnection::first()?->last_error ?? 'unknown error')]]);
        }

        return response()->json(['read' => $read] + $this->present(GoogleBusinessConnection::first()));
    }

    /** DELETE /site-builder/google - disconnects; reviews already read stay, to hide or delete by hand. */
    public function disconnect(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        GoogleBusinessConnection::query()->delete();

        return response()->json($this->present(null));
    }

    private function client(): GoogleBusiness
    {
        $connection = GoogleBusinessConnection::first()
            ?? throw ValidationException::withMessages(['google' => ['Connect your Google Business Profile first.']]);

        return new GoogleBusiness($connection);
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can('manage_website_content')) {
            abort(403, 'You do not have permission to edit the website.');
        }
    }

    /** @return array<string, mixed> */
    private function present(?GoogleBusinessConnection $c): array
    {
        return [
            'available' => GoogleBusiness::configured(),
            'connected' => $c !== null,
            'google_email' => $c?->google_email,
            'location' => $c?->location,
            'location_title' => $c?->location_title,
            'average_rating' => $c?->average_rating,
            'total_reviews' => $c?->total_reviews,
            'synced_at' => $c?->synced_at?->toIso8601String(),
            'last_error' => $c?->last_error,
        ];
    }
}
