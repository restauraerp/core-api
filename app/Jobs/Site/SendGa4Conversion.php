<?php

namespace App\Jobs\Site;

use App\Models\SiteIntegration;
use Illuminate\Support\Facades\Http;

/**
 * A website conversion to Google Analytics 4 by the Measurement Protocol - the
 * server-side half Google offers. Uses the visitor's GA client id from the
 * _ga cookie, so the purchase joins their session and its traffic source;
 * without one it is still counted, just unattributed.
 *
 * When this is set up the browser does not also send `purchase` (see
 * SiteIntegration::publicTags), so GA4 counts each order once.
 */
class SendGa4Conversion extends SiteConversionJob
{
    protected function send(SiteIntegration $integration): void
    {
        if (! $integration->sendsGa4()) {
            return;
        }

        $c = $this->conversion;
        $params = $c['event'] === 'purchase'
            ? [
                'transaction_id' => $c['transaction_id'],
                'value' => $c['value'],
                'currency' => $c['currency'],
                'items' => array_map(fn ($i) => [
                    'item_id' => $i['id'], 'item_name' => $i['name'], 'quantity' => $i['quantity'], 'price' => $i['price'],
                ], $c['items']),
            ]
            : ['method' => 'website'];

        Http::timeout(15)
            ->asJson()
            ->post('https://www.google-analytics.com/mp/collect?'.http_build_query([
                'measurement_id' => $integration->ga4_id,
                'api_secret' => $integration->ga4_api_secret,
            ]), [
                // Required; a stable stand-in for a visitor with no _ga cookie.
                'client_id' => $c['ga_client_id'] ?: crc32((string) $c['event_id']).'.'.$c['time'],
                'timestamp_micros' => $c['time'] * 1_000_000,
                'events' => [[
                    'name' => $c['event'] === 'purchase' ? 'purchase' : 'generate_lead',
                    'params' => array_filter($params + ['engagement_time_msec' => 1], fn ($v) => $v !== null),
                ]],
            ])
            ->throw();
    }
}
