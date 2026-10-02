<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RestrictScopedTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Access tokens for the RestoraERP MCP server (mcp/site-builder), so Claude
 * Code or another MCP client can build the restaurant's website.
 *
 * A token acts as the person who made it, but only on the site builder
 * (RestrictScopedTokens) - never orders, accounts or staff. Shown once, when
 * made; expires; revocable here. Each person sees and revokes their own.
 */
class SiteAccessTokenController extends Controller
{
    private const PREFIX = 'site-builder: ';

    /** GET /site-builder/access-tokens */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json(['tokens' => $this->tokens($request)->map(fn (PersonalAccessToken $t) => [
            'id' => $t->id,
            'name' => substr($t->name, strlen(self::PREFIX)),
            'created_at' => $t->created_at?->toIso8601String(),
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
        ])->values()]);
    }

    /** POST /site-builder/access-tokens - the plain token is in this answer only. */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'expires_in_days' => ['nullable', 'integer', 'in:7,30,90,365'],
        ]);

        if ($this->tokens($request)->count() >= 10) {
            abort(422, 'You have 10 access tokens already. Revoke one you no longer use first.');
        }

        $token = $request->user()->createToken(
            self::PREFIX.$validated['name'],
            [RestrictScopedTokens::SITE_BUILDER],
            now()->addDays($validated['expires_in_days'] ?? 90),
        );

        return response()->json([
            'id' => $token->accessToken->id,
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ], 201);
    }

    /** DELETE /site-builder/access-tokens/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage($request);

        $this->tokens($request)->firstWhere('id', $id)?->delete()
            ?? abort(404, 'No such access token.');

        return response()->json(null, 204);
    }

    /** @return Collection<int, PersonalAccessToken> */
    private function tokens(Request $request)
    {
        return $request->user()->tokens()
            ->where('name', 'like', self::PREFIX.'%')
            ->orderByDesc('id')
            ->get();
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can('manage_website_content')) {
            abort(403, 'You do not have permission to edit the website.');
        }
    }
}
