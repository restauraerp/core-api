<?php

namespace App\Support\Telegram;

/**
 * The delivery board's statuses (Pending, Out for Delivery, Delivered,
 * Failed) as the notifications need them: a closed delivery no longer needs
 * the customer's details in anyone's chat.
 */
final class DeliveryStatus
{
    public const DELIVERED = 'Delivered';

    public static function isClosed(?string $status): bool
    {
        return in_array(strtolower((string) $status), ['delivered', 'failed', 'cancelled', 'canceled'], true);
    }

    public static function isDelivered(?string $status): bool
    {
        return strtolower((string) $status) === 'delivered';
    }
}
