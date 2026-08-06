<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trial
    |--------------------------------------------------------------------------
    |
    | Days of full access a newly created tenant gets. A trial has no grace
    | period: the day it ends the restaurant drops to read-only.
    |
    */

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Grace periods
    |--------------------------------------------------------------------------
    |
    | Days of continued full access after a paid subscription's end date, per
    | billing cycle. A yearly renewal usually goes through an invoice and a
    | bank transfer rather than a card charge, so it gets longer.
    |
    | Grace applies only to tenants who have paid at least once. Trials get
    | none - that is the difference between "your payment is late" and "you
    | never started".
    |
    */

    'grace_days' => [
        'monthly' => (int) env('BILLING_GRACE_MONTHLY', 7),
        'yearly' => (int) env('BILLING_GRACE_YEARLY', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Entitlement cache
    |--------------------------------------------------------------------------
    |
    | Subscription state is resolved on every request, so it is cached rather
    | than recomputed from dates each time.
    |
    | Pinned to redis explicitly instead of using the default store: CACHE_STORE
    | is unset on these boxes, so config('cache.default') is `database` - which
    | would put a SELECT back on the hot path this cache exists to remove.
    |
    | The TTL is a ceiling, not the real lifetime. Subscription::resolve()
    | shortens it so an entry never outlives the next state change (trial end,
    | subscription end, grace end), and every command that changes a tenant's
    | billing forgets the key outright.
    |
    */

    'cache' => [
        'store' => env('BILLING_CACHE_STORE', 'redis'),
        'ttl' => (int) env('BILLING_CACHE_TTL', 900),
        'prefix' => 'tenant-subscription',
    ],

];
