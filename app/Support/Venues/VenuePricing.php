<?php

namespace App\Support\Venues;

use App\Models\Hall;
use App\Models\VenuePackage;
use Illuminate\Support\Carbon;

/**
 * What a venue booking costs: the hall's rent (per event, or per hour of the
 * event, in half hours), the food package per guest, extras, less any
 * discount - never below nothing. Worked out here, on the server, so the
 * price shown is the price stored.
 */
final class VenuePricing
{
    /**
     * @param  list<array{label: string, amount: float|int}>  $extras
     * @return array{hours: float, rent_amount: float, package_name: ?string, package_price: float, food_amount: float, extras_amount: float, discount: float, total: float}
     */
    public static function quote(Hall $hall, Carbon $start, Carbon $end, int $guests, ?VenuePackage $package, array $extras, float $discount): array
    {
        $hours = max(0.5, ceil($start->diffInMinutes($end) / 30) / 2);
        $rent = (float) $hall->price * ($hall->rent_type === Hall::PER_HOUR ? $hours : 1);
        $food = $package ? $package->price_per_person * $guests : 0.0;
        $extrasAmount = array_sum(array_map(fn ($e) => (float) $e['amount'], $extras));
        $subtotal = $rent + $food + $extrasAmount;

        return [
            'hours' => $hours,
            'rent_amount' => round($rent, 2),
            'package_name' => $package?->name,
            'package_price' => round($package?->price_per_person ?? 0, 2),
            'food_amount' => round($food, 2),
            'extras_amount' => round($extrasAmount, 2),
            'discount' => round(min($discount, $subtotal), 2),
            'total' => round(max(0, $subtotal - $discount), 2),
        ];
    }
}
