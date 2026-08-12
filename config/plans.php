<?php

use App\Support\Billing\Modules;

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription tiers
    |--------------------------------------------------------------------------
    |
    | What a customer actually buys, mirroring the pricing section on the
    | marketing site (website/resources/views/home/below-fold.blade.php). This
    | is the single source of truth for entitlements: outlet caps and module
    | access are both read from here, so a tier change is a config change.
    |
    | These used to be named after the deployment model (shared/dedicated/cloud)
    | and carried caps of 2/5/unlimited that matched neither the site nor the
    | code enforcing them - which was nothing. Names now match what the customer
    | was sold.
    |
    | `outlets` NULL means unlimited. Prices are BDT, for reference and support;
    | nothing bills off them yet.
    |
    | `hosting` is descriptive only. Which server a tenant runs on is a
    | provisioning fact the application cannot enforce.
    |
    */

    'default' => 'starter',

    'tiers' => [

        'starter' => [
            'name' => 'Starter',
            'description' => 'For a single outlet getting started.',
            'hosting' => 'shared',
            'outlets' => 1,
            // The list price, and the authoritative one: the marketing site
            // asks this API what a tier costs when it builds an order, so this
            // is what a customer is actually charged.
            //
            // Landing-page visitors are sold Starter at a lower rate. That
            // discount is NOT expressed here - it is per customer, not per
            // tier, and lives on the marketing site as a locked price against
            // the customer record. See the website README, "Two prices for
            // Starter". A second tier here would have been the other way to do
            // it and was rejected: it would need hiding from every plan picker
            // and pricing table, and entitlements would then be identical
            // across two tiers that differ only in price.
            'price_monthly' => 790,
            // Ten months of the monthly price - two free by paying up front.
            'price_yearly' => 7900,
            'setup_fee' => 0,
            'modules' => Modules::CORE,
        ],

        'growth' => [
            'name' => 'Growth',
            'description' => 'For small and medium restaurants.',
            'hosting' => 'shared',
            // Deliberately 1, same as Starter: Growth sells modules, not
            // outlets. Confirmed against the pricing page.
            'outlets' => 1,
            'price_monthly' => 2490,
            'price_yearly' => 29900,
            'setup_fee' => 8000,
            'modules' => Modules::ALL,
        ],

        'business' => [
            'name' => 'Business',
            'description' => 'For growing restaurants needing data isolation.',
            'hosting' => 'dedicated',
            'outlets' => 3,
            'price_monthly' => 5990,
            'price_yearly' => 69900,
            'setup_fee' => 20000,
            'modules' => Modules::ALL,
        ],

        'enterprise' => [
            'name' => 'Enterprise',
            'description' => 'For chains on enterprise-grade infrastructure.',
            'hosting' => 'cloud',
            'outlets' => null,
            'price_monthly' => 14990,
            'price_yearly' => 149900,
            'setup_fee' => 50000,
            'modules' => Modules::ALL,
        ],

    ],

];
