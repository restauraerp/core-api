<?php

namespace App\Jobs\Telegram;

use App\Models\TelegramBot;
use App\Models\User;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramError;

/** A plain message from the restaurant's bot - replies to /start, test messages. */
class SendTelegramText extends TelegramJob
{
    public function __construct(
        int $tenantId,
        public string $chatId,
        public string $html,
        public ?int $userId = null,
    ) {
        parent::__construct($tenantId);
    }

    protected function send(TelegramClient $telegram, TelegramBot $bot): void
    {
        $telegram->sendMessage($this->chatId, $this->html);
    }

    protected function chatUnreachable(TelegramError $e): void
    {
        if ($this->userId !== null) {
            User::find($this->userId)?->unlinkTelegram($e->getMessage());
        }
    }
}
