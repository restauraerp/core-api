<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default page size
    |--------------------------------------------------------------------------
    |
    | Read here rather than with env() at the call site. `php artisan optimize`
    | runs on every deploy and caches the config, and from that moment Laravel
    | stops loading .env at all - so `env('PAGINATION_LIMIT')` in a controller
    | quietly stops reflecting the environment and falls back to whatever
    | default that call site happened to pass. Thirteen controllers still read
    | it that way; tests/Feature/EnvUsageTest.php lists them.
    |
    */

    'limit' => (int) env('PAGINATION_LIMIT', 15),

];
