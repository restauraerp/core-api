<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Restaurant websites (the site builder)
    |--------------------------------------------------------------------------
    |
    | base_domain   Restaurant sites live at {code}.{base_domain}. Must match
    |               SITE_BASE_DOMAIN in the front's .env.
    | cname_target  What a restaurant points its own domain's CNAME at. Must
    |               resolve to this server.
    | server_ip     For domains that cannot take a CNAME (a bare banglabistro.com),
    |               the A record to set instead.
    |
    */

    'base_domain' => env('SITE_BASE_DOMAIN', 'restauraerp.com'),

    'cname_target' => env('SITE_CNAME_TARGET', 'sites.'.env('SITE_BASE_DOMAIN', 'restauraerp.com')),

    'server_ip' => env('SITE_SERVER_IP'),

];
