<?php

namespace App\Support\Telegram;

use App\Models\Delivery;
use App\Support\Branding\RestaurantBranding;

/**
 * What a rider's Telegram chat says about a delivery.
 *
 * Two versions. The assignment carries everything needed to get the food to
 * the door: the order, the customer's name, phone and address, a map button
 * and what to collect. Once the delivery is over - delivered, reassigned or
 * cancelled - the message is edited down to the order and the customer's
 * name, so a rider's chat history is not a list of strangers' phone numbers
 * and home addresses.
 *
 * HTML parse mode throughout; every value from the database is escaped.
 */
class DeliveryMessage
{
    /** Prefix of the Delivered button's callback_data: "dlv:<delivery id>". */
    public const DELIVERED_ACTION = 'dlv';

    /** @return array{text: string, reply_markup: array} */
    public static function assignment(Delivery $delivery): array
    {
        $order = $delivery->order;
        $currency = RestaurantBranding::current()['currency'] ?? '৳';
        $address = $delivery->address ?: $order?->delivery_address;

        $lines = [
            '🛵 <b>New delivery - Order #'.$order?->id.'</b>'.self::token($delivery),
        ];

        if ($order?->location) {
            $lines[] = 'From: '.e($order->location->name);
        }

        $lines[] = '';
        $lines[] = 'Customer: <b>'.e($order?->customer?->name ?? 'Walk-in').'</b>';

        if ($phone = $order?->customer?->phone) {
            $lines[] = 'Phone: '.e($phone);
        }

        if ($address) {
            $lines[] = 'Address: '.e($address);
        }

        if ($order?->delivery_time) {
            $lines[] = 'Deliver by: '.$order->delivery_time->format('d M, h:i A');
        }

        if ($note = $order?->customer_note) {
            $lines[] = 'Note: '.e($note);
        }

        $items = $order?->items ?? collect();
        if ($items->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<b>Items</b>';
            foreach ($items as $item) {
                $lines[] = '• '.(int) $item->quantity.' × '.e($item->product?->name ?? 'Item');
            }
        }

        if ($order) {
            $due = (float) $order->amount_outstanding;
            $lines[] = '';
            $lines[] = $due > 0
                ? '💵 Collect: <b>'.e($currency).number_format($due, 2).'</b>'
                : '💵 Already paid - nothing to collect.';
        }

        $buttons = [];

        if ($map = self::mapLink($order?->latitude, $order?->longitude, $address)) {
            $buttons[] = [['text' => '📍 Open in Maps', 'url' => $map]];
        }

        $buttons[] = [['text' => '✅ Delivered', 'callback_data' => self::DELIVERED_ACTION.':'.$delivery->getKey()]];

        return [
            'text' => implode("\n", $lines),
            'reply_markup' => ['inline_keyboard' => $buttons],
        ];
    }

    /**
     * The message once the rider no longer needs the details: the order and
     * the customer's name, nothing that reaches them.
     */
    public static function closed(Delivery $delivery, string $reason): string
    {
        $order = $delivery->order;

        $headline = match ($reason) {
            'delivered' => '✅ <b>Delivered - Order #'.$order?->id.'</b>',
            'reassigned' => '↪️ <b>Order #'.$order?->id.' was given to another rider</b>',
            default => '✖️ <b>Order #'.$order?->id.' was cancelled</b>',
        };

        return implode("\n", [
            $headline.self::token($delivery),
            'Customer: '.e($order?->customer?->name ?? 'Walk-in'),
            '',
            '<i>Contact details removed.</i>',
        ]);
    }

    private static function token(Delivery $delivery): string
    {
        $token = $delivery->order?->token_number;

        return $token !== null ? ' · Token '.(int) $token : '';
    }

    private static function mapLink(mixed $lat, mixed $lng, ?string $address): ?string
    {
        if (is_numeric($lat) && is_numeric($lng) && ((float) $lat !== 0.0 || (float) $lng !== 0.0)) {
            return 'https://www.google.com/maps/search/?api=1&query='.((float) $lat).','.((float) $lng);
        }

        if ($address) {
            return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($address);
        }

        return null;
    }
}
