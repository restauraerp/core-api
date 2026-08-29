<?php

namespace App\Http\Controllers;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Relays walkthrough progress from the app to the marketing site.
 *
 * The same hop as DemoLeadController and for the same reason: the website owns the
 * customer records and the lifecycle ladder, and this side should not hold a second
 * copy of either. Progress is reported here because this is where the walkthrough
 * runs; it is stored there because that is where it means something.
 *
 * Unlike the demo lead relay, this answers on every deployment rather than only on
 * a demo one - the trial walkthrough and the video tutorials run on real tenants.
 *
 * It relays in both directions. `store()` sends a reading over; `show()` asks for
 * the last one back, which is what lets somebody resume a walkthrough they left
 * half-finished. The demo cannot keep that position on this side: it runs on one
 * shared restaurant, so a position stored here would be one position shared by
 * every visitor in it.
 */
class WalkthroughProgressController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:demo,trial,video'],
            'percent' => ['required', 'integer', 'min:0', 'max:100'],
            'key' => ['nullable', 'string', 'max:100'],
            // Opaque here on purpose: the website issued it and the website reads
            // it. This side only carries it, exactly as it carries the Facebook
            // cookies for a demo lead.
            'ref' => ['nullable', 'string', 'max:2000'],
            'seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        $tenantCode = $this->tenantCode();

        $website = rtrim((string) config('platform.website_url'), '/');
        $secret = (string) config('platform.token');

        if ($website === '' || $secret === '') {
            Log::warning('Walkthrough progress could not be relayed: website URL or platform token missing.');

            // The visitor is not waiting on this; never turn a telemetry gap into a
            // visible error in the product.
            return response()->json(['status' => 'skipped']);
        }

        try {
            $response = Http::withToken($secret)
                ->acceptJson()
                ->asJson()
                ->timeout(10)
                ->post($website.'/api/marketing/progress', $validated + [
                    'tenant_code' => $tenantCode,
                    'occurred_at' => now()->toIso8601String(),
                ]);

            if ($response->failed()) {
                Log::error('Website refused relayed walkthrough progress: '.$response->status().' '.$response->body());
            }
        } catch (\Throwable $e) {
            Log::error('Failed to relay walkthrough progress: '.$e->getMessage());
        }

        return response()->json(['status' => 'accepted']);
    }

    /**
     * Where this person had got to, relayed back from the website.
     *
     * Answers a usable shape whatever happens. Somebody opening the product is
     * not waiting on this, and a walkthrough that refuses to start because a
     * lookup timed out is worse than one that starts from the beginning - so
     * every failure lands on the same "nothing found" answer.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:demo,trial,video'],
            'ref' => ['nullable', 'string', 'max:2000'],
        ]);

        $empty = [
            'found' => false,
            'kind' => $validated['kind'],
            'percent' => 0,
            'last_key' => null,
            'keys_seen' => [],
            'completed' => false,
        ];

        $website = rtrim((string) config('platform.website_url'), '/');
        $secret = (string) config('platform.token');

        if ($website === '' || $secret === '') {
            return response()->json($empty);
        }

        try {
            $response = Http::withToken($secret)
                ->acceptJson()
                ->timeout(10)
                ->get($website.'/api/marketing/progress', $validated + [
                    'tenant_code' => $this->tenantCode(),
                ]);

            if ($response->failed()) {
                Log::warning('Website refused a walkthrough progress lookup: '.$response->status());

                return response()->json($empty);
            }

            return response()->json($response->json() ?: $empty);
        } catch (\Throwable $e) {
            Log::warning('Failed to read walkthrough progress: '.$e->getMessage());

            return response()->json($empty);
        }
    }

    /**
     * Which restaurant is asking, from the token rather than from the request.
     *
     * A client-supplied tenant code would let one restaurant read - and write -
     * progress against another's account.
     *
     * Resolved by hand, and outside the tenant scope, for two reasons that both
     * end in the same silent failure.
     *
     * This route sits outside the `auth:sanctum` group - deliberately, since a
     * demo visitor has no account at all - so nothing here ever resolves a user
     * and `$request->user()` is null however good the token is. And because the
     * route also skips ResolveTenant, TenantScope is in its fail-closed state:
     * it appends `1 = 0` to every User query, so Sanctum looks up the token's
     * owner and finds nobody. The token is itself the proof of identity here,
     * and personal access tokens are not tenant-owned, so bypassing the scope
     * for this one lookup gives away nothing.
     *
     * `slug` is the restaurant code; there is no `restaurant_code` column, which
     * was a third way to arrive at null.
     *
     * Between them, every trial and video reading was accepted here and then
     * dropped by the website for want of anybody to attribute it to. Nobody ever
     * reached the walkthrough-completed rungs, and the campaigns that begin there
     * could never fire.
     */
    private function tenantCode(): ?string
    {
        $user = app(TenantContext::class)->runWithoutScoping(
            fn () => Auth::guard('sanctum')->user(),
        );

        return $user?->tenant?->slug
            ?? (app()->bound('tenant') ? app('tenant')?->slug : null);
    }
}
