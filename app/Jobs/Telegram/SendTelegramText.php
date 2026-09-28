<?php

namespace App\Jobs\Telegram;

use App\Models\TelegramBot;
use App\Support\Telegram\TelegramClient;

/** A plain message from the restaurant's bot - replies to /start, test messages. */
class SendTelegramText extends TelegramJob
{
    public function __construct(int $tenantId, public string $chatId, public string $html)
    {
        parent::__construct($tenantId);
    }

    protected function send(TelegramClient $telegram, TelegramBot $bot): void
    {
        $telegram->sendMessage($this->chatId, $this->html);
    }
}
