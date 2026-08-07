<?php

namespace App\Support\Orders;

use App\Models\WebsiteSetting;

/**
 * How long before an order is due the kitchen needs to start it.
 *
 * A restaurant knows its own timings: a biryani wants ninety minutes, a
 * sandwich ten. The window lives in the tenant's settings as
 * `kitchen_lead_minutes` rather than as a constant, and everything that asks
 * "what has to be started now?" - the kitchen display, the orders screen, any
 * later tablet or ticket printer - reads it from here, so they cannot disagree
 * about what "soon" means.
 */
class KitchenLead
{
    public const SETTING = 'kitchen_lead_minutes';

    /** An hour is the sensible default for a kitchen that has not said otherwise. */
    public const DEFAULT_MINUTES = 60;

    private const MAX_MINUTES = 24 * 60;

    /**
     * Deliberately not memoised. The window belongs to one restaurant, and this
     * class outlives a single tenant in anything long-running - a queue worker
     * walking tenants, or an Octane worker serving two restaurants in a row -
     * where a remembered value would be the wrong restaurant's. It is one
     * indexed lookup on a table with a few dozen rows.
     */
    public function minutes(): int
    {
        $value = WebsiteSetting::where('key', self::SETTING)->value('value');

        if ($value === null || ! is_numeric($value)) {
            return self::DEFAULT_MINUTES;
        }

        // A negative or absurd window would either hide everything or show the
        // whole week as urgent; neither is a warning anybody can act on.
        return (int) max(0, min(self::MAX_MINUTES, (int) $value));
    }
}
