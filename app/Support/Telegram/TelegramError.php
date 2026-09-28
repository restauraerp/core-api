<?php

namespace App\Support\Telegram;

use RuntimeException;

/**
 * Telegram said no, or could not be reached.
 *
 * The code is Telegram's own error_code (0 when there was no answer), so a
 * caller can tell the three cases that need handling apart: 429 (slow down,
 * for retryAfter seconds), 403 (the rider blocked or deleted the bot - stop
 * sending to them) and everything else.
 */
class TelegramError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $errorCode = 0,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $errorCode);
    }

    public function isRateLimited(): bool
    {
        return $this->errorCode === 429;
    }

    /** The rider blocked the bot, deleted their account, or never started it. */
    public function isUnreachableChat(): bool
    {
        return $this->errorCode === 403;
    }

    /** Editing a message that no longer exists, or that already says this. */
    public function isStaleMessage(): bool
    {
        return $this->errorCode === 400 && (
            str_contains($this->getMessage(), 'message to edit not found')
            || str_contains($this->getMessage(), 'message is not modified')
            || str_contains($this->getMessage(), "message can't be edited")
        );
    }
}
