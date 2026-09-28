<?php

namespace App\Jobs\Telegram;

use App\Models\Delivery;
use App\Models\TelegramBot;
use App\Support\Telegram\DeliveryMessage;
use App\Support\Telegram\DeliveryStatus;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramError;

/**
 * Tells a rider they have been given a delivery.
 *
 * Built when it runs, not when it was queued, so a rate-limited message still
 * carries the order as it is now - and is dropped if the delivery was
 * finished, reassigned or deleted in the meantime.
 */
class NotifyRiderOfDelivery extends TelegramJob
{
    public function __construct(int $tenantId, public int $deliveryId)
    {
        parent::__construct($tenantId);
    }

    protected function send(TelegramClient $telegram, TelegramBot $bot): void
    {
        $delivery = Delivery::with(['rider', 'order.items.product', 'order.customer', 'order.location', 'order.payments'])
            ->find($this->deliveryId);

        if ($delivery === null || $delivery->rider === null || ! $delivery->rider->hasTelegram()) {
            return;
        }

        if (DeliveryStatus::isClosed($delivery->status)) {
            return;
        }

        $chatId = (string) $delivery->rider->telegram_chat_id;

        // Already told this rider about this delivery.
        if ($delivery->telegram_chat_id === $chatId && $delivery->telegram_message_id !== null && $delivery->telegram_redacted_at === null) {
            return;
        }

        $message = DeliveryMessage::assignment($delivery);

        $sent = $telegram->sendMessage($chatId, $message['text'], [
            'reply_markup' => $message['reply_markup'],
            // Not forwardable, not saveable: it carries a customer's details.
            'protect_content' => true,
        ]);

        $delivery->forceFill([
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => (int) ($sent['message_id'] ?? 0) ?: null,
            'telegram_sent_at' => now(),
            'telegram_redacted_at' => null,
        ])->saveQuietly();
    }

    protected function chatUnreachable(TelegramError $e): void
    {
        // The rider blocked or deleted the bot. Stop trying to reach them
        // until they are onboarded again; the dashboard shows them unlinked.
        Delivery::with('rider')->find($this->deliveryId)?->rider?->unlinkTelegram();
    }
}
