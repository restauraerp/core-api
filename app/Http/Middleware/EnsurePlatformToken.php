<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the platform API, which the marketing website calls server to server.
 *
 * These endpoints create tenants and turn subscriptions on, so they are the
 * most dangerous surface in the application. They are deliberately not
 * reachable with a user token: there is no user yet when a restaurant is being
 * created, and no customer should ever be able to activate their own
 * subscription.
 */
class EnsurePlatformToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('platform.token');

        // An unset token disables the API rather than opening it. A deployment
        // that forgot the secret must fail closed.
        if ($expected === '') {
            return response()->json([
                'message' => 'The platform API is not configured on this deployment.',
                'error' => 'platform_api_disabled',
            ], 503);
        }

        $presented = (string) ($request->bearerToken() ?? $request->header('X-Platform-Token', ''));

        if ($presented === '' || ! hash_equals($expected, $presented)) {
            return response()->json([
                'message' => 'Invalid platform credentials.',
                'error' => 'platform_token_invalid',
            ], 401);
        }

        return $next($request);
    }
}
