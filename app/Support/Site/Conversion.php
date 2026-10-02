<?php

namespace App\Support\Site;

use App\Models\Order;
use App\Models\Reservation;
use Illuminate\Http\Request;

/**
 * One thing a website visitor did that the restaurant's ad accounts should
 * hear about - an online order (purchase) or a table request (lead) - with
 * what the browser knew about the visitor, for matching.
 *
 * Built while the request is still here (IP, user agent, cookies) and handed
 * to the queue as a plain array: the jobs that send it must not need the
 * request, and nothing in it is a model that could change underneath them.
 *
 * The event id is the same one the browser gives its own pixel event
 * ("order-42"), which is how Meta knows the two are one purchase.
 */
final class Conversion
{
    /** What the browser sends alongside an order or booking. */
    public const REQUEST_RULES = [
        'tracking' => ['nullable', 'array'],
        'tracking.fbp' => ['nullable', 'string', 'max:255'],
        'tracking.fbc' => ['nullable', 'string', 'max:255'],
        'tracking.ga_client_id' => ['nullable', 'string', 'max:64', 'regex:/^[0-9]+\.[0-9]+$/'],
        'tracking.page_url' => ['nullable', 'string', 'max:1000', 'regex:#^https?://#i'],
    ];

    /** @return array<string, mixed> */
    public static function forOrder(Order $order, Request $request, string $name, string $phone): array
    {
        $order->loadMissing('items.product:id,name');

        return self::base($request, $name, $phone, $order->customer_id) + [
            'event' => 'purchase',
            'event_id' => 'order-'.$order->id,
            'transaction_id' => (string) $order->id,
            'value' => round((float) $order->total, 2),
            'items' => $order->items->map(fn ($item) => [
                'id' => (string) $item->product_id,
                'name' => $item->product?->name,
                'quantity' => (int) $item->quantity,
                'price' => round((float) $item->price, 2),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function forReservation(Reservation $reservation, Request $request, string $name, string $phone): array
    {
        return self::base($request, $name, $phone, $reservation->customer_id) + [
            'event' => 'lead',
            'event_id' => 'reservation-'.$reservation->id,
            'transaction_id' => null,
            'value' => null,
            'items' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function base(Request $request, string $name, string $phone, ?int $customerId): array
    {
        $tracking = (array) $request->input('tracking', []);

        return [
            'time' => now()->getTimestamp(),
            'currency' => (string) config('site.currency', 'BDT'),
            'name' => $name,
            'phone' => $phone,
            'customer_id' => $customerId,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'page_url' => $tracking['page_url'] ?? null,
            'fbp' => $tracking['fbp'] ?? null,
            'fbc' => $tracking['fbc'] ?? null,
            'ga_client_id' => $tracking['ga_client_id'] ?? null,
        ];
    }

    /** Meta wants phone numbers as digits with the country code: 01711... is 8801711... */
    public static function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return str_starts_with($digits, '0') && strlen($digits) === 11 ? '88'.$digits : $digits;
    }
}
