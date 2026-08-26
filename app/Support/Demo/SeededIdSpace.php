<?php

namespace App\Support\Demo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The id headroom the demo seed reserves, and gives back.
 *
 * OrderSeeder bumps each of these tables' AUTO_INCREMENT by 200,000 before it
 * bulk-inserts, so that real inserts arriving while the demo rebuilds land
 * above its explicit-id range instead of colliding with it. The app is live
 * during a refresh, so that part is worth doing.
 *
 * What was missing is the other half. The headroom was never given back, and
 * the refresh runs nightly: every run moved each counter 200,000 further along
 * whether it used the room or not - 73 million ids per table per year from the
 * cron alone, before counting deploys, against a demo that actually seeds about
 * 360 expenses.
 */
class SeededIdSpace
{
    /**
     * Tables OrderSeeder reserves a block on.
     *
     * @var list<string>
     */
    public const TABLES = ['orders', 'expenses', 'incomes', 'purchase_orders', 'consumption_logs'];

    /**
     * Puts each counter back to one above its highest row.
     *
     * Safe only after the seed has finished, which is where it is called from:
     * everything in the table by then - demo rows and any real rows written
     * during the rebuild - sits below that mark, so no later insert can
     * collide. MySQL ignores an ALTER that would lower the counter beneath
     * existing rows, so the worst case is that this does nothing.
     *
     * @return array<string, int> the counter each table was left at
     */
    public static function reclaim(): array
    {
        $result = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $next = ((int) DB::table($table)->max('id')) + 1;

            DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = {$next}");

            $result[$table] = $next;
        }

        return $result;
    }
}
