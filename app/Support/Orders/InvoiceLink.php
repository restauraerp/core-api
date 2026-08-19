<?php

namespace App\Support\Orders;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * A link to one order's invoice that anybody holding it can open.
 *
 * The customer is not a user of this system and never will be, so the link has
 * to carry its own authority. Laravel signs the API route; the signature covers
 * the order id and the expiry, so the URL cannot be edited into somebody else's
 * invoice and stops working on its own.
 *
 * The address handed out points at the *front*, not the API, because what the
 * customer wants is a readable page rather than JSON. The front page forwards
 * the `expires` and `signature` it was given straight back to the API, which
 * validates them against its own route - so the signature never has to survive
 * being computed for one host and checked on another.
 *
 * There is deliberately no PDF. A hosted page renders on any phone, is one
 * fewer thing to store and expire, and every mobile browser can already save or
 * print it if the customer wants a file.
 */
class InvoiceLink
{
    /**
     * Long enough to be useful, short enough that a forwarded message does not
     * expose an order forever. A customer who needs it again can be sent a
     * fresh one.
     */
    public const TTL_DAYS = 30;

    /**
     * @return array{url: string, expires_at: string}
     */
    public static function for(Order $order): array
    {
        $appUrl = rtrim((string) config('platform.app_url'), '/');

        if ($appUrl === '') {
            throw new RuntimeException(
                'FRONTEND_URL is not set, so no invoice link can be built.'
            );
        }

        $expiresAt = Carbon::now()->addDays(self::TTL_DAYS);

        // Signed over the path and query only, never the host.
        //
        // The API answers on more than one address: directly on its own domain,
        // and through the front's Next rewrite at /api/v1, which is how a
        // browser reaches it. An absolute signature covers the host, so a link
        // minted against one address and opened through the other fails as
        // tampered - which is exactly what happened, and reads to the customer
        // as "this invoice link has expired".
        //
        // Relative signing still covers the order id and the expiry, which is
        // all the signature has to bind. Validated with `signed:relative` on
        // the route.
        $signed = URL::temporarySignedRoute(
            'orders.invoice',
            $expiresAt,
            ['order' => $order->getKey()],
            absolute: false,
        );

        // Only the query matters to the front - it rebuilds the API call from
        // the order id in its own path plus these two values.
        $query = parse_url($signed, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            throw new RuntimeException('Signed invoice route produced no query string.');
        }

        return [
            'url' => $appUrl.'/invoice/'.$order->getKey().'?'.$query,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
