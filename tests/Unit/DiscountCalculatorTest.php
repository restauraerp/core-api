<?php

namespace Tests\Unit;

use App\Models\Discount;
use App\Support\Sales\DiscountCalculator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What comes off a bill, and in which order.
 *
 * The order matters arithmetically, so it is pinned here rather than left to
 * whichever call site runs first.
 */
class DiscountCalculatorTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function line(float $price, int $qty = 1, ?string $type = null, float|int|null $value = null): array
    {
        return ['price' => $price, 'qty' => $qty, 'discount_type' => $type, 'discount_value' => $value];
    }

    private function coupon(string $type, float $value): Discount
    {
        return new Discount(['code' => 'TEST', 'discount_type' => $type, 'value' => $value]);
    }

    #[Test]
    public function a_flat_reduction_comes_straight_off(): void
    {
        $this->assertSame(200.0, DiscountCalculator::amount('flat', 200, 1000));
    }

    #[Test]
    public function a_percentage_is_of_the_base_it_is_applied_to(): void
    {
        $this->assertSame(100.0, DiscountCalculator::amount('percent', 10, 1000));
    }

    /**
     * A flat discount bigger than the thing it is applied to is capped, not
     * allowed to become a refund the restaurant never agreed to.
     */
    #[Test]
    public function nothing_can_be_discounted_below_zero(): void
    {
        $this->assertSame(100.0, DiscountCalculator::amount('flat', 99999, 100));
    }

    #[Test]
    public function a_percentage_applies_to_the_whole_line_not_the_unit_price(): void
    {
        $money = DiscountCalculator::forOrder([$this->line(500, 2, 'percent', 10)], null, null, null);

        $this->assertSame(1000.0, $money['subtotal']);
        $this->assertSame(100.0, $money['item_discount']);
    }

    /**
     * The stacking order, which is the whole point: items first, then the
     * coupon against what is left, then the manager's reduction against what is
     * left after that. Applying all three to the gross would take off more.
     */
    #[Test]
    public function the_three_kinds_stack_in_order(): void
    {
        $money = DiscountCalculator::forOrder(
            [$this->line(1000, 1, 'flat', 200), $this->line(500, 2, 'percent', 10)],
            $this->coupon('flat', 100),
            'percent',
            5,
        );

        $this->assertSame(2000.0, $money['subtotal']);
        // 200 off the first line, 100 off the second (10% of 1000).
        $this->assertSame(300.0, $money['item_discount']);
        // Coupon against the 1700 that remains.
        $this->assertSame(100.0, $money['coupon_discount']);
        // 5% of the 1600 left after that, not of the 2000 gross.
        $this->assertSame(80.0, $money['order_discount']);
        $this->assertSame(480.0, $money['discount_amount']);
    }

    #[Test]
    public function two_half_price_reductions_do_not_make_it_free(): void
    {
        $money = DiscountCalculator::forOrder(
            [$this->line(1000, 1, 'percent', 50)],
            null,
            'percent',
            50,
        );

        // 500 off the line, then 250 off what remains - three quarters, not all.
        $this->assertSame(750.0, $money['discount_amount']);
    }

    /**
     * The coupon table stores 'percentage'; the till sends 'percent'. Both mean
     * the same thing and both have to work.
     */
    #[Test]
    public function the_coupon_tables_spelling_of_percentage_is_understood(): void
    {
        $money = DiscountCalculator::forOrder([$this->line(1000)], $this->coupon('percentage', 10), null, null);

        $this->assertSame(100.0, $money['coupon_discount']);
    }

    #[Test]
    public function an_order_with_no_discounts_has_none(): void
    {
        $money = DiscountCalculator::forOrder([$this->line(1000), $this->line(500, 2)], null, null, null);

        $this->assertSame(2000.0, $money['subtotal']);
        $this->assertSame(0.0, $money['discount_amount']);
    }

    #[Test]
    public function each_line_reports_what_came_off_it(): void
    {
        $money = DiscountCalculator::forOrder(
            [$this->line(1000, 1, 'flat', 200), $this->line(500, 1)],
            null,
            null,
            null,
        );

        $this->assertSame(200.0, $money['lines'][0]['discount_amount']);
        $this->assertSame(800.0, $money['lines'][0]['net']);
        $this->assertSame(0.0, $money['lines'][1]['discount_amount']);
    }

    #[Test]
    public function a_negative_or_zero_value_takes_nothing_off(): void
    {
        $this->assertSame(0.0, DiscountCalculator::amount('flat', -50, 1000));
        $this->assertSame(0.0, DiscountCalculator::amount('percent', 0, 1000));
        $this->assertSame(0.0, DiscountCalculator::amount('flat', 50, 0));
    }

    /**
     * The Flutter till, and any web build older than the structured fields,
     * posts a finished discount_amount. Dropping it would silently lose what
     * the cashier took off on every order those clients place.
     */
    #[Test]
    public function a_discount_amount_from_an_older_client_is_honoured(): void
    {
        $money = DiscountCalculator::forOrder([$this->line(1000)], null, null, null, 200);

        $this->assertSame(200.0, $money['order_discount']);
        $this->assertSame(200.0, $money['discount_amount']);
    }

    #[Test]
    public function the_structured_fields_win_over_a_posted_amount(): void
    {
        // A client that sends both means the structured one; the amount is the
        // same number it computed from it.
        $money = DiscountCalculator::forOrder([$this->line(1000)], null, 'percent', 10, 999);

        $this->assertSame(100.0, $money['order_discount']);
    }

    #[Test]
    public function a_posted_amount_is_still_capped(): void
    {
        $money = DiscountCalculator::forOrder([$this->line(1000)], null, null, null, 99999);

        $this->assertSame(1000.0, $money['discount_amount']);
    }
}
