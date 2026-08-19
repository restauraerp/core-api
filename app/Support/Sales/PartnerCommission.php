<?php

namespace App\Support\Sales;

use App\Models\Partner;

/**
 * What a partner keeps out of an order it sent in.
 *
 * The rate is read from the partner and then *copied onto the order*, the same
 * way tax_amount is. Rates get renegotiated - an aggregator moves from 25% to
 * 22% - and if the commission were recomputed from the current rate every time
 * anyone looked, last quarter's earnings would silently restate themselves. The
 * rate is configuration; the amount is a fact about one sale on one day.
 *
 * Charged on the order total, delivery and tax included, because that is what
 * the aggregators in this market actually invoice against. If that turns out to
 * be wrong for a particular partner the base belongs on the partner record, not
 * hidden in this function.
 */
class PartnerCommission
{
    /**
     * @return array{rate: float, amount: float}
     */
    public static function on(Partner $partner, float $total): array
    {
        $rate = (float) $partner->commission_rate;
        $amount = round($total * $rate / 100, 2);

        return [
            'rate' => $rate,
            // Never more than the sale itself, whatever a mistyped rate says.
            'amount' => min($amount, round($total, 2)),
        ];
    }
}
