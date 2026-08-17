<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        // Taken from the authenticated tenant rather than from the request. A
        // client-supplied tenant code would let one restaurant report progress
        // against another's account.
        // `bound()` first: this route skips ResolveTenant, so on a demo visit -
        // which has no tenant at all - nothing has ever put one in the container,
        // and reaching for it directly throws rather than returning null.
        $tenantCode = $request->user()?->tenant?->restaurant_code
            ?? (app()->bound('tenant') ? app('tenant')?->restaurant_code : null);

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
}
