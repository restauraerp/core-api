<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\PasswordResetLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * "I have forgotten my password."
 *
 * Public and unauthenticated, which is what makes the shape of the response
 * matter more than the logic: see below.
 */
class PasswordResetController extends Controller
{
    public function __construct(private PasswordResetLink $links) {}

    /**
     * Emails a reset link, if there is anybody to email.
     *
     * Always answers the same way - accepted - whatever happened. An endpoint
     * that says "no account with that address" is a way to test whether a
     * restaurant banks with us, one address at a time, and this one takes no
     * authentication at all. The customer is told to check their inbox either
     * way; only somebody who really has an account finds anything there.
     *
     * The demo account is refused for a different reason, and equally quietly:
     * its credentials are published on the marketing site, so a reset would let
     * any passer-by lock every other visitor out of the demo.
     */
    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc,strict', 'max:255'],
            // Optional, and only used to disambiguate: one address can own more
            // than one restaurant.
            'restaurant_code' => ['nullable', 'string', 'max:255'],
        ]);

        $accepted = [
            'status' => 'accepted',
            'message' => 'If that address has an account, a reset link is on its way to it.',
        ];

        $user = $this->resolveUser($validated['email'], $validated['restaurant_code'] ?? null);

        if ($user === null) {
            return response()->json($accepted);
        }

        try {
            $this->links->send($user);
        } catch (\Throwable $e) {
            // Logged rather than surfaced: telling an unauthenticated caller
            // that something went wrong for this address confirms the address
            // exists, which is the thing the flat response exists to hide.
            Log::error('Password reset link could not be sent: '.$e->getMessage(), [
                'email' => $validated['email'],
            ]);
        }

        return response()->json($accepted);
    }

    /**
     * The account behind an address.
     *
     * Unscoped on purpose - this runs before any tenant is resolved. Where an
     * address owns several restaurants and no code was given, the request is
     * refused rather than guessed at: sending a link for the wrong restaurant
     * would be worse than sending none.
     */
    private function resolveUser(string $email, ?string $restaurantCode): ?User
    {
        $query = User::withoutGlobalScopes()->where('email', $email);

        if ($restaurantCode !== null && $restaurantCode !== '') {
            $tenantId = Tenant::withoutGlobalScopes()
                ->where('slug', $restaurantCode)
                ->value('id');

            if ($tenantId === null) {
                return null;
            }

            $query->where('tenant_id', $tenantId);
        }

        $users = $query->limit(2)->get();

        return $users->count() === 1 ? $users->first() : null;
    }
}
