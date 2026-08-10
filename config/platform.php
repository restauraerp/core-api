<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform API
    |--------------------------------------------------------------------------
    |
    | The marketing website provisions trials and activates subscriptions by
    | calling /api/v1/platform/*. Those calls are server to server and cross
    | tenant - creating a restaurant means no tenant exists yet - so they are
    | authenticated by a shared secret rather than by a user token, and they sit
    | outside the tenant-resolution middleware.
    |
    | Leave the token empty to disable the platform API outright: every request
    | is refused rather than allowed through unauthenticated.
    |
    */

    'token' => env('PLATFORM_API_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | One-time login links
    |--------------------------------------------------------------------------
    |
    | A freshly provisioned trial owner has no password, so they are handed a
    | single-use link instead. It buys one login and is spent on redemption.
    |
    */

    'login_link_ttl_hours' => (int) env('PLATFORM_LOGIN_LINK_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Where customers log in
    |--------------------------------------------------------------------------
    |
    | The admin front. Used to build the login URL and the single-use login link
    | handed back with a freshly provisioned trial. Same value as FRONTEND_URL,
    | which CORS already reads.
    |
    | Deliberately NO default. A localhost fallback here does not fail - it
    | quietly emails "http://localhost:3000/login" to a paying customer, who has
    | no way to tell it is wrong and no way to reach their account. An unset
    | value refuses to build a link at all, which surfaces on the first trial
    | instead of on a support call.
    |
    */

    'app_url' => env('FRONTEND_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | The marketing site
    |--------------------------------------------------------------------------
    |
    | Where a trial owner is sent to pay, and where branded emails and relayed
    | conversion events are posted. Must be reachable FROM this application, so
    | in Docker it is the compose service name, not localhost.
    |
    | No default, for the same reason as above.
    |
    */

    'website_url' => env('WEBSITE_URL', ''),

];
