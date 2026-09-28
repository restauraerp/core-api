<?php

namespace App\Jobs\Telegram;

use App\Models\Delivery;
use App\Models\TelegramBot;
use App\Support\Telegram\TelegramClient;

/**
 * Edits a delivery message down to the order and the customer's name.
 *
 * The text is worked out when the job is queued: a deleted delivery has
 * nothing left to build it from by the time this runs.
 */
class CloseDeliveryMessage extends TelegramJob
{
    public function __construct(
        int $tenantId,
        public string $chatId,
        public int $messageId,
        public string $html,
        public ?int $deliveryId = null,
    ) {
        parent::__construct($tenantId);
    }

    protected function send(TelegramClient $telegram, TelegramBot $bot): void
    {
        // Editing without reply_markup also removes the buttons.
        $telegram->editMessageText($this->chatId, $this->messageId, $this->html);

        if ($this->deliveryId !== null) {
            Delivery::whereKey($this->deliveryId)
                ->where('telegram_message_id', $this->messageId)
                ->update(['telegram_redacted_at' => now()]);
        }
    }
}
