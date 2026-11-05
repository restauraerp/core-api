<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | RestoraERP's own Google Cloud project, for restaurants connecting their
    | Google Business Profile (reviews). Needs the Business Profile APIs
    | enabled and the OAuth consent screen verified for business.manage. The
    | redirect URI registered there is {FRONTEND_URL}/api/v1/integrations/google/callback
    | unless GOOGLE_BUSINESS_REDIRECT_URI says otherwise.
    */
    'google_business' => [
        'client_id' => env('GOOGLE_BUSINESS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_BUSINESS_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_BUSINESS_REDIRECT_URI'),
    ],

    /*
    | RestoraERP's own Meta app, for restaurants connecting their Facebook Page
    | and Instagram account (posts on their website). Needs Facebook Login for
    | Business and App Review for pages_show_list, pages_read_engagement,
    | instagram_basic and business_management. Redirect URI:
    | {FRONTEND_URL}/api/v1/integrations/meta/callback unless META_REDIRECT_URI.
    */
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'redirect' => env('META_REDIRECT_URI'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
