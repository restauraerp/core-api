<?php

namespace App\Jobs\Site;

use App\Models\SiteIntegration;
use App\Support\Site\Conversion;
use Illuminate\Support\Facades\Http;

/**
 * A website conversion to Meta's Conversions API, as the restaurant's pixel.
 *
 * Personal details are hashed (SHA-256, normalised as Meta asks) before they
 * leave; the IP, user agent and the _fbp/_fbc cookies go as they are, which
 * is how Meta matches the event to an ad click. The event id matches the
 * browser pixel's, so the pair counts once.
 */
class SendMetaConversion extends SiteConversionJob
{
    private const EVENTS = ['purchase' => 'Purchase', 'lead' => 'Lead'];

    protected function send(SiteIntegration $integration): void
    {
        if (! $integration->sendsMeta()) {
            return;
        }

        $c = $this->conversion;
        $hash = fn (?string $v) => $v !== null && $v !== '' ? [hash('sha256', $v)] : null;
        $first = mb_strtolower(trim(explode(' ', trim((string) $c['name']))[0] ?? ''));

        $event = array_filter([
            'event_name' => self::EVENTS[$c['event']] ?? 'Lead',
            'event_time' => $c['time'],
            'event_id' => $c['event_id'],
            'action_source' => 'website',
            'event_source_url' => $c['page_url'],
            'user_data' => array_filter([
                'ph' => $hash(Conversion::normalisePhone((string) $c['phone'])),
                'fn' => $hash($first),
                'external_id' => $hash($c['customer_id'] ? (string) $c['customer_id'] : null),
                'client_ip_address' => $c['ip'],
                'client_user_agent' => $c['user_agent'],
                'fbp' => $c['fbp'],
                'fbc' => $c['fbc'],
            ], fn ($v) => $v !== null && $v !== ''),
            'custom_data' => $c['event'] === 'purchase' ? [
                'currency' => $c['currency'],
                'value' => $c['value'],
                'order_id' => $c['transaction_id'],
                'content_type' => 'product',
                'contents' => array_map(fn ($i) => ['id' => $i['id'], 'quantity' => $i['quantity'], 'item_price' => $i['price']], $c['items']),
            ] : null,
        ], fn ($v) => $v !== null);

        $version = config('site.meta_graph');

        Http::timeout(15)
            ->asJson()
            ->post("https://graph.facebook.com/{$version}/{$integration->livePixelId()}/events", array_filter([
                'data' => [$event],
                'test_event_code' => $integration->meta_test_event_code,
                'access_token' => $integration->meta_capi_token,
            ]))
            ->throw();
    }
}
