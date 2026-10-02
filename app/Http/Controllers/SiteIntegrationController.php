<?php

namespace App\Http\Controllers;

use App\Models\SiteIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The website's measurement tags: GTM, GA4, Meta pixel, Clarity - and the
 * secrets core-api needs to report orders and bookings server-side (Meta
 * Conversions API, GA4 Measurement Protocol). Behind module:website and
 * manage_website_content. Live on save; not part of the site's versions.
 *
 * Secrets are write-only: the answer says whether one is set, never what it is.
 * Sending an empty string clears one; leaving it out keeps it.
 */
class SiteIntegrationController extends Controller
{
    /** GET /site-builder/integrations */
    public function show(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json($this->present(SiteIntegration::current()));
    }

    /** PUT /site-builder/integrations */
    public function update(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'gtm_id' => ['nullable', 'string', 'regex:/^GTM-[A-Z0-9]{4,12}$/'],
            'ga4_id' => ['nullable', 'string', 'regex:/^G-[A-Z0-9]{4,14}$/'],
            'meta_pixel_id' => ['nullable', 'string', 'regex:/^\d{10,20}$/'],
            'clarity_id' => ['nullable', 'string', 'regex:/^[a-z0-9]{6,20}$/'],
            'meta_capi_token' => ['sometimes', 'nullable', 'string', 'max:500', 'regex:/^[A-Za-z0-9_-]+$/'],
            'meta_test_event_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9]+$/'],
            'ga4_api_secret' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
        ], [
            'gtm_id.regex' => 'A Tag Manager id looks like GTM-ABC1234.',
            'ga4_id.regex' => 'A GA4 measurement id looks like G-ABC123XYZ.',
            'meta_pixel_id.regex' => 'A Meta pixel id is a long number, like 1234567890123456.',
            'clarity_id.regex' => 'A Clarity project id is lower-case letters and numbers, like abc12de3fg.',
            'meta_capi_token.regex' => 'Paste the access token exactly as Events Manager shows it.',
            'ga4_api_secret.regex' => 'Paste the API secret exactly as Google Analytics shows it.',
        ]);

        $integration = SiteIntegration::current() ?? new SiteIntegration;

        // A field left out keeps its value - which is how a secret survives a
        // save from a form that never has it to send back.
        foreach (array_keys($validated) as $field) {
            if (array_key_exists($field, $validated)) {
                $integration->{$field} = $validated[$field] ?: null;
            }
        }

        $integration->save();

        return response()->json($this->present($integration));
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can('manage_website_content')) {
            abort(403, 'You do not have permission to edit the website.');
        }
    }

    /** @return array<string, mixed> */
    private function present(?SiteIntegration $i): array
    {
        return [
            'gtm_id' => $i?->gtm_id,
            'ga4_id' => $i?->ga4_id,
            'meta_pixel_id' => $i?->meta_pixel_id,
            'clarity_id' => $i?->clarity_id,
            'meta_test_event_code' => $i?->meta_test_event_code,
            'has_meta_capi_token' => (bool) $i?->meta_capi_token,
            'has_ga4_api_secret' => (bool) $i?->ga4_api_secret,
            'sends_meta' => (bool) $i?->sendsMeta(),
            'sends_ga4' => (bool) $i?->sendsGa4(),
        ];
    }
}
