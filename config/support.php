<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform support and billing contacts
    |--------------------------------------------------------------------------
    |
    | Returned in every response that refuses a write because of subscription
    | state, so a restaurant that has just been stopped mid-shift is told who to
    | pay rather than only that it failed.
    |
    | These are RestoraERP's own contacts, identical for every tenant -
    | deliberately not the restaurant's own contact_email or whatsapp_number
    | from website_settings, which are how *their* customers reach *them*.
    |
    | Read at request time, so a changed number needs no deploy.
    |
    */

    'email' => env('SUPPORT_EMAIL', 'support@restauraerp.com'),
    'phone' => env('SUPPORT_PHONE'),
    'whatsapp' => env('SUPPORT_WHATSAPP'),

    // Where a lapsed customer goes to see plans and pay.
    'billing_url' => env('BILLING_URL', 'https://restauraerp.com/#pricing'),

];
