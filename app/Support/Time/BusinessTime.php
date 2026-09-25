<?php

namespace App\Support\Time;

use App\Models\WebsiteSetting;
use Illuminate\Support\Carbon;

/**
 * When a restaurant's day begins and ends, and in which timezone.
 *
 * Three tenant settings live here so nothing re-derives them:
 *
 *   - **Timezone.** Data is stored in the app's own timezone (APP_TIMEZONE),
 *     unchanged. This is only for *reading*: a figure shown to the user, or a
 *     day boundary used in a calculation, is expressed in the restaurant's own
 *     timezone. A restaurant that never sets one simply reads back in the app
 *     timezone, so the common single-region case is a no-op.
 *
 *   - **Day start.** Not always midnight. A kitchen serving past 1am is still
 *     working the evening that began the night before, so the business day can
 *     be set to roll over at, say, 04:00. `orders.business_date` and the token
 *     run reset on this boundary rather than on the calendar date.
 *
 *   - **Week start.** Which weekday a week begins on for reporting. Sunday by
 *     default; a restaurant can move it to any day.
 *
 * Deliberately not memoised, for the reason KitchenLead gives: this outlives a
 * single tenant in a queue or Octane worker, where a remembered value would be
 * the wrong restaurant's. It is a handful of indexed lookups on a tiny table.
 */
class BusinessTime
{
    public const TZ_SETTING = 'business_timezone';
    public const DAY_START_SETTING = 'business_day_start_time';
    public const WEEK_START_SETTING = 'week_start_day';

    /** Midnight, unless the restaurant says otherwise. */
    public const DEFAULT_DAY_START = '00:00';

    /** 0 = Sunday .. 6 = Saturday. */
    public const DEFAULT_WEEK_START = 0;

    private function raw(string $key): ?string
    {
        $value = WebsiteSetting::where('key', $key)->value('value');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The restaurant's reporting timezone, falling back to the app's own.
     *
     * Validated against the real timezone list so a malformed value can never
     * make Carbon throw mid-report; an unknown zone reads as the app timezone.
     */
    public function timezone(): string
    {
        $tz = $this->raw(self::TZ_SETTING);

        if ($tz !== null && in_array($tz, timezone_identifiers_list(), true)) {
            return $tz;
        }

        return config('app.timezone');
    }

    /** Minutes past midnight (in the restaurant's timezone) at which the day rolls over. */
    public function dayStartOffsetMinutes(): int
    {
        $raw = $this->raw(self::DAY_START_SETTING) ?? self::DEFAULT_DAY_START;

        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
            return 0;
        }

        $minutes = ((int) $m[1]) * 60 + (int) $m[2];

        // A boundary at or past the next midnight would fold the day onto
        // itself; clamp to a valid time-of-day.
        return max(0, min(24 * 60 - 1, $minutes));
    }

    /** The weekday a reporting week starts on: 0 (Sunday) .. 6 (Saturday). */
    public function weekStartDay(): int
    {
        $raw = $this->raw(self::WEEK_START_SETTING);

        if ($raw === null || ! is_numeric($raw)) {
            return self::DEFAULT_WEEK_START;
        }

        return (int) max(0, min(6, (int) $raw));
    }

    /** "Now", read in the restaurant's timezone. */
    public function now(): Carbon
    {
        return Carbon::now()->setTimezone($this->timezone());
    }

    /**
     * The business day a moment belongs to, as Y-m-d in the restaurant's timezone.
     *
     * The instant is read in the restaurant's timezone, then shifted back by the
     * day-start offset so anything before the cutoff counts as the previous day.
     * `subMinutes` keeps it to one step: with a 04:00 start, 03:30 lands at
     * 23:30 the day before and the date falls out.
     */
    public function businessDate(?Carbon $at = null): string
    {
        return ($at ?? Carbon::now())
            ->copy()
            ->setTimezone($this->timezone())
            ->subMinutes($this->dayStartOffsetMinutes())
            ->toDateString();
    }
}
