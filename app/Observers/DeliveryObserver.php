<?php

namespace App\Observers;

use App\Jobs\Telegram\CloseDeliveryMessage;
use App\Jobs\Telegram\NotifyRiderOfDelivery;
use App\Models\Delivery;
use App\Models\TelegramBot;
use App\Support\Telegram\DeliveryMessage;
use App\Support\Telegram\DeliveryStatus;

/**
 * Keeps riders' Telegram chats in step with the delivery board.
 *
 * On the model rather than in DeliveryController so every way a delivery
 * changes - the board, the rider's own Delivered button, anything added later
 * - notifies the same way:
 *
 *   - a rider is assigned, or swapped: the new rider is sent the delivery;
 *   - the previous rider's message is cut down to the customer's name;
 *   - delivered, failed or deleted: the same, for whoever had it.
 *
 * Everything is queued after the transaction commits, so a rolled-back save
 * never messages anybody, and nothing here waits on Telegram.
 */
class DeliveryObserver
{
    public function saved(Delivery $delivery): void
    {
        if (! $this->hasBot($delivery)) {
            return;
        }

        $riderChanged = $delivery->wasRecentlyCreated || $delivery->wasChanged('rider_id');
        $closed = DeliveryStatus::isClosed($delivery->status);

        // The rider it used to be with no longer needs the customer's details.
        if ($riderChanged && ! $delivery->wasRecentlyCreated) {
            $this->closeOriginalMessage($delivery, 'reassigned');
        }

        if ($closed && $delivery->wasChanged('status') && ! $riderChanged) {
            $this->closeCurrentMessage($delivery, DeliveryStatus::isDelivered($delivery->status) ? 'delivered' : 'cancelled');
        }

        if ($riderChanged && $delivery->rider_id !== null && ! $closed) {
            NotifyRiderOfDelivery::dispatch((int) $delivery->tenant_id, (int) $delivery->getKey())->afterCommit();
        }
    }

    public function deleted(Delivery $delivery): void
    {
        if ($this->hasBot($delivery)) {
            $this->closeCurrentMessage($delivery, 'cancelled', keepRecord: false);
        }
    }

    private function closeOriginalMessage(Delivery $delivery, string $reason): void
    {
        $chatId = $delivery->getOriginal('telegram_chat_id');
        $messageId = $delivery->getOriginal('telegram_message_id');

        if ($chatId && $messageId && $delivery->getOriginal('telegram_redacted_at') === null) {
            CloseDeliveryMessage::dispatch(
                (int) $delivery->tenant_id,
                (string) $chatId,
                (int) $messageId,
                DeliveryMessage::closed($delivery, $reason),
                (int) $delivery->getKey(),
            )->afterCommit();
        }
    }

    private function closeCurrentMessage(Delivery $delivery, string $reason, bool $keepRecord = true): void
    {
        if ($delivery->telegram_chat_id && $delivery->telegram_message_id && $delivery->telegram_redacted_at === null) {
            CloseDeliveryMessage::dispatch(
                (int) $delivery->tenant_id,
                (string) $delivery->telegram_chat_id,
                (int) $delivery->telegram_message_id,
                DeliveryMessage::closed($delivery, $reason),
                $keepRecord ? (int) $delivery->getKey() : null,
            )->afterCommit();
        }
    }

    /** Nothing to do for a restaurant that has not connected a bot. */
    private function hasBot(Delivery $delivery): bool
    {
        return TelegramBot::withoutGlobalScopes()->where('tenant_id', $delivery->tenant_id)->exists();
    }
}
