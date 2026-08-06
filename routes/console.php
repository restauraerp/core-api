<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Suspends tenants whose trial or subscription has run out, so `status` matches
 * what the API already enforces (Tenant::isActive() rejects a lapsed tenant the
 * moment the date passes, without waiting for this).
 *
 * Runs just after midnight, since that is when a trial ending "today" actually
 * ends. withoutOverlapping because the sweep is not instant on a box with many
 * tenants and a second copy would fight the first for row locks.
 *
 * NOTE: this needs `php artisan schedule:run` in cron on the box. core-api had
 * no scheduler at all before this - see the core-api scheduler section in
 * infra/templates/user_data.sh.tpl.
 */
Schedule::command('tenants:expire')
    ->dailyAt('00:15')
    ->withoutOverlapping();
