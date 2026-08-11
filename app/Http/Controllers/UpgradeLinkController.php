<?php

namespace App\Http\Controllers;

use App\Models\UpgradeToken;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Sends a signed-in customer to the website's payment page as themselves.
 *
 * Two halves. The front asks for a link while authenticated; the website
 * redeems the token over the platform channel to learn which restaurant is
 * paying. Neither half trusts a tenant slug in a URL, because a slug in a URL
 * is something anybody can type.
 */
class UpgradeLinkController extends Controller
{
    public function __construct(private TenantContext $context) {}

    /**
     * Mints an upgrade link for the signed-in user's restaurant.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $this->context->get();

        if ($tenant === null) {
            return response()->json([
                'message' => 'No restaurant in context.',
                'error' => 'tenant_missing',
            ], 400);
        }

        // The public URL, not the one this application calls the website on:
        // this is followed by the customer's browser, which cannot resolve a
        // Docker service name. See config/platform.php.
        $website = rtrim((string) config('platform.website_public_url'), '/');

        // Same reasoning as the login link: better an error the customer can
        // report than a button that silently goes nowhere.
        if ($website === '') {
            Log::error('WEBSITE_URL is not set; upgrade links cannot be built.');

            return response()->json([
                'message' => 'Upgrades are not available on this deployment.',
                'error' => 'website_url_missing',
            ], 503);
        }

        $token = UpgradeToken::issueFor($user);

        return response()->json([
            'url' => $website.'/upgrade?token='.urlencode($token),
            'expires_in_minutes' => UpgradeToken::TTL_MINUTES,
        ]);
    }

    /**
     * Redeems a token for the website. Platform-authenticated: only our own
     * marketing site may ask who a token belongs to.
     */
    public function redeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
        ]);

        $token = UpgradeToken::findLive($validated['token']);

        if ($token === null || ! $token->redeem()) {
            return response()->json([
                'message' => 'This upgrade link is no longer valid.',
                'error' => 'upgrade_token_invalid',
            ], 422);
        }

        $tenant = $token->tenant()->first();

        // The tenant has to be in context before the user is loaded: User is
        // scoped by tenant, and this route runs outside tenant resolution, so
        // an unscoped read here silently returns nothing.
        if ($tenant !== null) {
            $this->context->set($tenant);
        }

        $user = $token->user()->first();

        if ($tenant === null || $user === null) {
            return response()->json([
                'message' => 'This upgrade link is no longer valid.',
                'error' => 'upgrade_token_invalid',
            ], 422);
        }

        return response()->json([
            'tenant' => [
                'restaurant_code' => $tenant->slug,
                'name' => $tenant->name,
                'plan' => $tenant->plan,
                'status' => $tenant->status,
            ],
            'owner' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $tenant->contact_phone,
            ],
        ]);
    }
}
