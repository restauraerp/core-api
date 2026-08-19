<?php

namespace App\Support\Sales;

use App\Models\Discount;

/**
 * What comes off a bill, worked out on the server.
 *
 * The POS used to compute the discount in the browser and post the figure,
 * which is the same fault tax had before TaxCalculator: a number the client
 * chose, stored as though it were a fact, and then fed to reporting and
 * accounting. A till that posts its own discount can post any discount.
 *
 * Three things can reduce one bill and they stack in a fixed order, because
 * the order changes the answer:
 *
 *   1. Per-item discounts. "The steak came out cold, take 200 off it."
 *   2. A redeemed coupon, against what is left.
 *   3. An order-level discount the cashier decided on, against what is left
 *      after that.
 *
 * Percentages always apply to what remains at their step rather than to the
 * gross, so two 50% reductions take three quarters off rather than all of it.
 * Nothing can push a line or a bill below zero: a flat discount larger than
 * what it is applied to is capped, not allowed to turn into a refund the
 * restaurant never agreed to.
 */
class DiscountCalculator
{
    public const FLAT = 'flat';

    public const PERCENT = 'percent';

    /**
     * One reduction against one base.
     */
    public static function amount(?string $type, float|int|null $value, float $base): float
    {
        $value = (float) ($value ?? 0);

        if ($value <= 0 || $base <= 0) {
            return 0.0;
        }

        $amount = self::isPercent($type) ? $base * $value / 100 : $value;

        return round(min($amount, $base), 2);
    }

    /**
     * Percent unless told otherwise. `percentage` is accepted because that is
     * the string the existing `discounts` coupon table stores.
     */
    private static function isPercent(?string $type): bool
    {
        return in_array($type, [self::PERCENT, 'percentage'], true);
    }

    /**
     * Every reduction on one order, in the order they stack.
     *
     * @param  list<array{price: float|int, qty: int, discount_type?: ?string, discount_value?: float|int|null}>  $lines
     * @return array{
     *     subtotal: float,
     *     item_discount: float,
     *     coupon_discount: float,
     *     order_discount: float,
     *     discount_amount: float,
     *     lines: list<array{gross: float, discount_amount: float, net: float}>
     * }
     */
    public static function forOrder(
        array $lines,
        ?Discount $coupon,
        ?string $orderType,
        float|int|null $orderValue,
        float|int|null $legacyAmount = null,
    ): array {
        // A client that knows nothing about discount_type/discount_value - the
        // Flutter till, and any web build older than this - posts a finished
        // discount_amount instead. Treating it as a flat reduction on the bill
        // keeps those orders correct rather than silently dropping what the
        // cashier took off.
        //
        // It is still capped like every other reduction, so this is not a way
        // back to "the client decides": the worst a bad value can do is take
        // the bill to zero.
        if (($orderValue === null || (float) $orderValue <= 0) && $legacyAmount !== null && (float) $legacyAmount > 0) {
            $orderType = self::FLAT;
            $orderValue = $legacyAmount;
        }

        $subtotal = 0.0;
        $itemDiscount = 0.0;
        $breakdown = [];

        foreach ($lines as $line) {
            $gross = round((float) $line['price'] * (int) $line['qty'], 2);
            $off = self::amount($line['discount_type'] ?? null, $line['discount_value'] ?? null, $gross);

            $subtotal += $gross;
            $itemDiscount += $off;

            $breakdown[] = [
                'gross' => $gross,
                'discount_amount' => $off,
                'net' => round($gross - $off, 2),
            ];
        }

        $subtotal = round($subtotal, 2);
        $itemDiscount = round($itemDiscount, 2);

        $afterItems = round($subtotal - $itemDiscount, 2);
        $couponDiscount = $coupon === null
            ? 0.0
            : self::amount($coupon->discount_type, $coupon->value, $afterItems);

        $afterCoupon = round($afterItems - $couponDiscount, 2);
        $orderDiscount = self::amount($orderType, $orderValue, $afterCoupon);

        return [
            'subtotal' => $subtotal,
            'item_discount' => $itemDiscount,
            'coupon_discount' => $couponDiscount,
            'order_discount' => $orderDiscount,
            'discount_amount' => round($itemDiscount + $couponDiscount + $orderDiscount, 2),
            'lines' => $breakdown,
        ];
    }
}
