<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rider notifications over Telegram
    |--------------------------------------------------------------------------
    |
    | Each restaurant connects its own bot from Integration in the dashboard.
    | Sending is throttled per bot: at most `per_second` messages a second and
    | `per_minute` a minute. Telegram itself allows more (about one a second
    | per chat, thirty a second per bot); these are ours, to keep a busy
    | restaurant's bot well clear of Telegram's limits.
    |
    | `webhook_url` is the public origin Telegram posts updates to. It must be
    | HTTPS on 443, 80, 88 or 8443. Defaults to APP_URL.
    |
    */

    'per_second' => (int) env('TELEGRAM_PER_SECOND', 1),

    'per_minute' => (int) env('TELEGRAM_PER_MINUTE', 30),

    'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),

];
