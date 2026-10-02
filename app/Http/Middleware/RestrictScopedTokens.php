<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps narrow access tokens to what they were made for.
 *
 * A login token carries every ability ('*') and passes straight through, as
 * does anything not made as a site-builder token. A site-builder token - made in Site builder > Claude & MCP for the RestoraERP
 * MCP server - only reaches the site builder: its pages, theme, versions and
 * integrations, and the public menu it builds from. Not orders, accounts,
 * staff or anything else the person could reach when signed in, and not the
 * token endpoints, so a token cannot mint itself a successor.
 *
 * Runs on every API request after ResolveTenant, which has already resolved
 * (and memoised) the token's user.
 */
class RestrictScopedTokens
{
    public const SITE_BUILDER = 'site-builder';

    /** Paths a site-builder token may use, under api/v1/. */
    private const SITE_BUILDER_PATHS = [
        'site-builder', 'site-builder/*',
        'site-pages', 'site-pages/*',
        'order/*',
    ];

    /** ...and read only. */
    private const SITE_BUILDER_READ = ['google-reviews', 'social-links'];

    public function handle(Request $request, Closure $next): Response
    {
        $token = Auth::guard('sanctum')->user()?->currentAccessToken();

        // Only a token made as a site-builder token is narrowed - read from
        // its stored abilities, not can(), which a '*' token also passes.
        $abilities = $token instanceof PersonalAccessToken ? (array) ($token->abilities ?? []) : [];
        if (! in_array(self::SITE_BUILDER, $abilities, true) || in_array('*', $abilities, true)) {
            return $next($request);
        }

        $path = substr($request->path(), strlen('api/v1/'));
        $allowed = ! str_starts_with($path, 'site-builder/access-tokens')
            && (
                collect(self::SITE_BUILDER_PATHS)->contains(fn ($p) => $request->is('api/v1/'.$p))
                || ($request->isMethod('GET') && in_array($path, self::SITE_BUILDER_READ, true))
            );

        if (! $allowed) {
            return response()->json(['message' => 'This access token only works for the site builder.'], 403);
        }

        return $next($request);
    }
}
