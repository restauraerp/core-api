<?php

namespace App\Support\Sales;

use App\Models\TaxRule;

/**
 * How much tax a sale carries, according to the restaurant's own tax rules.
 *
 * This exists because the POS used to hardcode `subtotal * 0.1` in the browser
 * and post the result. Every order on every tenant was charged 10% VAT - a rate
 * that appeared nowhere in the system, while the seeded rule said 5% and was
 * switched off. The stored tax_amount fed reporting and accounting, so the
 * error was not only on the bill.
 *
 * Two rules follow from that:
 *
 *   1. No active tax rule means no tax. TenantProvisioner seeds VAT inactive on
 *      purpose - Bangladeshi restaurant VAT varies by category, so the owner
 *      turns it on once it is right for them. Charging in the meantime is
 *      guessing with a customer's money.
 *
 *   2. The server decides. A tax figure posted by a till is a number the client
 *      chose; the only figure worth storing is one derived from the tenant's
 *      configured rules.
 */
class TaxCalculator
{
    /**
     * Combined percentage of every active rule for the current tenant.
     *
     * Summed rather than compounded: two 5% rules are 10% of the same base, not
     * 5% of a total that already includes 5%.
     */
    public static function rate(): float
    {
        return (float) TaxRule::query()->where('is_active', true)->sum('percentage');
    }

    /**
     * Tax on an amount that has already had discounts taken off.
     */
    public static function on(float $taxable): float
    {
        if ($taxable <= 0) {
            return 0.0;
        }

        return round($taxable * (self::rate() / 100), 2);
    }
}
