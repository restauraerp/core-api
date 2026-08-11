<?php

namespace App\Http\Controllers;

use App\Models\OneTimeLoginToken;
use App\Models\Tenant;
use App\Services\WebsiteNotifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Exchanges a single-use login link for a session.
 *
 * A trial owner has never chosen a password, so there is nothing for them to
 * type. The link they were emailed is the credential; it is spent here, once,
 * and they are pushed straight at a "choose your password" screen.
 *
 * Runs outside tenant resolution: the caller has no restaurant code to send
 * because the whole point is that they have not been told anything yet. The
 * token carries the tenant instead.
 */
class OneTimeLoginController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private WebsiteNotifier $notifier,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
        ]);

        $token = OneTimeLoginToken::findLive($validated['token']);

        if ($token === null) {
            return response()->json([
                'message' => 'This login link is no longer valid. Ask for a new one.',
                'error' => 'login_link_invalid',
            ], 422);
        }

        // Spend it before anything else. Two clicks arriving together must not
        // both succeed, and the update is conditional on it still being unused.
        if (! $token->redeem()) {
            return response()->json([
                'message' => 'This login link has already been used.',
                'error' => 'login_link_spent',
            ], 422);
        }

        $tenant = Tenant::find($token->tenant_id);

        if ($tenant === null) {
            return response()->json([
                'message' => 'This login link is no longer valid.',
                'error' => 'login_link_invalid',
            ], 422);
        }

        // Everything from here reads through the tenant scope.
        $this->context->set($tenant);

        $user = $token->user()->first();

        if ($user === null) {
            return response()->json([
                'message' => 'This login link is no longer valid.',
                'error' => 'login_link_invalid',
            ], 422);
        }

        return response()->json([
            'user' => $user,
            'tenant' => $tenant,
            'token' => $user->createToken('auth_token')->plainTextToken,
            'must_set_password' => (bool) $user->must_set_password,
        ]);
    }

    /**
     * Sets the password for the signed-in user and clears the flag that has
     * been nagging them for one.
     */
    public function setPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'must_set_password' => false,
        ])->save();

        // Tell them it happened. The point is that the one person who did not
        // do this finds out, so it goes whether or not it was expected.
        $this->notifier->passwordChanged(
            email: $user->email,
            name: $user->name,
            restaurantName: $this->context->get()?->name,
        );

        return response()->json([
            'message' => 'Password set.',
            'must_set_password' => false,
        ]);
    }
}
