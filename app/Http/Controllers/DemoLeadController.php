<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Relays the demo app's 60-second Lead to the marketing site.
 *
 * The demo app has no Facebook access token and should not have one - all
 * server-side reporting lives on the website, so there is exactly one place
 * holding that secret. This endpoint is the hop between them.
 *
 * Only answers on a demo deployment, for the same reason demo-config does:
 * a production API has no demo visitors and no business relaying their events.
 */
class DemoLeadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // config/app.php already resolves DEMO_MODE; reading env() here as a
        // fallback would return null once the config is cached on deploy.
        abort_unless((bool) config('app.demo_mode'), 404);

        $validated = $request->validate([
            'event_id' => ['required', 'string', 'max:100'],
            'fbp' => ['nullable', 'string', 'max:255'],
            'fbc' => ['nullable', 'string', 'max:255'],
            'source_url' => ['nullable', 'string', 'max:2000'],
        ]);

        $website = rtrim((string) config('platform.website_url'), '/');
        $secret = (string) config('platform.token');

        if ($website === '' || $secret === '') {
            Log::warning('Demo lead could not be relayed: website URL or platform token missing.');

            // The visitor is not waiting on this; never turn a tracking gap
            // into a visible error in the product.
            return response()->json(['status' => 'skipped']);
        }

        try {
            $response = Http::withToken($secret)
                ->acceptJson()
                ->asJson()
                ->timeout(10)
                ->post($website.'/api/conversions/lead', $validated + [
                    // Taken here rather than trusted from the browser: these are
                    // matching signals, and a client-supplied IP is worthless.
                    'client_ip' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                    'event_time' => time(),
                ]);

            if ($response->failed()) {
                Log::error('Website refused a relayed demo lead: '.$response->status().' '.$response->body());
            }
        } catch (\Throwable $e) {
            Log::error('Failed to relay demo lead: '.$e->getMessage());
        }

        return response()->json(['status' => 'accepted']);
    }
}
