<?php

namespace App\Support\Orders;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The number the counter calls out: 1, 2, 3, restarting every business day.
 *
 * Two things this owns, and nothing else should re-derive:
 *
 *   - **Where the day breaks.** Not midnight. A kitchen closing at 1am is still
 *     working the evening that started the night before, and an order rung up
 *     at ten past twelve belongs on that shift's run of tokens rather than
 *     opening a new one. The boundary is 00:15 local time - see
 *     [self::DAY_STARTS_AT] - and `orders.business_date` is stamped with the
 *     answer at insert so nothing has to recompute it later.
 *
 *   - **Who gets which number.** Per branch, per day. Two outlets both handing
 *     out token 7 on the same afternoon is correct: the number is shouted
 *     across one room, and a chain-wide sequence would have a single counter
 *     calling out 4,891 by evening.
 *
 * The app runs in Asia/Dhaka (APP_TIMEZONE), so `created_at` is already local
 * wall-clock time and no conversion happens here. That is the same assumption
 * the reporting endpoints make; if the app timezone ever moves, both change
 * together.
 */
class TokenNumber
{
    /**
     * When one token day gives way to the next, local time.
     *
     * Change this in one place and both halves follow: new orders are stamped
     * against the new boundary, and already-numbered orders keep the
     * business_date they were issued under, so yesterday's slips still reprint
     * the token that was on them.
     */
    public const DAY_STARTS_AT = '00:15';

    public function __construct(private TenantContext $tenants) {}

    /**
     * The boundary as whole minutes past midnight, for the SQL below.
     */
    private static function offsetMinutes(): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', self::DAY_STARTS_AT));

        return $hours * 60 + $minutes;
    }

    /**
     * Numbers every order that has no token yet, then points each branch's
     * counter at the highest number now in use.
     *
     * Static and tenant-blind on purpose: the two callers - the migration that
     * introduced the column, and the demo seeder after it bulk-inserts two
     * years of orders - both work across whole tables rather than inside one
     * restaurant's request. The PARTITION BY still keeps every restaurant and
     * branch on its own sequence.
     *
     * Ordering is by created_at then id, so a day's tokens read in the order
     * the orders were taken; id alone would misnumber a bulk insert that did
     * not happen chronologically.
     *
     * Soft-deleted orders are numbered too - they were real orders that used up
     * a number on the day, and skipping them would renumber everything after.
     */
    public static function numberExistingOrders(): void
    {
        $offset = self::offsetMinutes();

        DB::statement("
            UPDATE orders o
            JOIN (
                SELECT
                    id,
                    DATE(created_at - INTERVAL {$offset} MINUTE) AS business_date,
                    ROW_NUMBER() OVER (
                        PARTITION BY tenant_id, location_id, DATE(created_at - INTERVAL {$offset} MINUTE)
                        ORDER BY created_at, id
                    ) AS token_number
                FROM orders
                WHERE token_number IS NULL
            ) numbered ON numbered.id = o.id
            SET o.business_date = numbered.business_date,
                o.token_number  = numbered.token_number
        ");

        self::syncCounters();
    }

    /**
     * Brings every counter up to the highest token its branch and day actually
     * hold, so the next real order continues the run instead of reissuing a
     * number that is already on a printed slip.
     */
    public static function syncCounters(): void
    {
        DB::statement('
            INSERT INTO order_token_counters (tenant_id, location_id, business_date, last_token, created_at, updated_at)
            SELECT tenant_id, location_id, business_date, MAX(token_number), NOW(), NOW()
            FROM orders
            WHERE business_date IS NOT NULL AND token_number IS NOT NULL
            GROUP BY tenant_id, location_id, business_date
            ON DUPLICATE KEY UPDATE
                last_token = GREATEST(last_token, VALUES(last_token)),
                updated_at = NOW()
        ');
    }

    /**
     * The business day a moment belongs to, as Y-m-d.
     *
     * Shifting back by the boundary rather than comparing against it keeps the
     * arithmetic to one step: 00:10 on the 27th lands at 23:55 on the 26th, and
     * the date falls out. Anything from 00:15 onwards stays on its own date.
     */
    public function businessDate(?Carbon $at = null): string
    {
        return ($at ?? Carbon::now())
            ->copy()
            ->subMinutes(self::offsetMinutes())
            ->toDateString();
    }

    /**
     * Claims the next token for a branch and returns it with the day it is for.
     *
     * Atomic by construction. The upsert takes a lock on the single counter row
     * for that branch and day, so two tills submitting at the same instant
     * queue behind each other and get 41 and 42 rather than both reading a
     * stale maximum and printing 41 twice. LAST_INSERT_ID() is what carries the
     * new value back out of an ON DUPLICATE KEY UPDATE - MySQL has no RETURNING
     * for this, and a follow-up SELECT would reintroduce the race it just shut.
     *
     * Call inside the order's transaction. A rolled-back order then releases
     * its number instead of leaving a hole in the day's run.
     *
     * @return array{business_date: string, token_number: int}
     */
    public function allocate(int $locationId, ?Carbon $at = null): array
    {
        $businessDate = $this->businessDate($at);
        $tenantId = $this->tenants->id();

        DB::statement(
            'INSERT INTO order_token_counters (tenant_id, location_id, business_date, last_token, created_at, updated_at)
             VALUES (?, ?, ?, LAST_INSERT_ID(1), NOW(), NOW())
             ON DUPLICATE KEY UPDATE last_token = LAST_INSERT_ID(last_token + 1), updated_at = NOW()',
            [$tenantId, $locationId, $businessDate],
        );

        return [
            'business_date' => $businessDate,
            'token_number' => (int) DB::selectOne('SELECT LAST_INSERT_ID() AS token')->token,
        ];
    }
}
