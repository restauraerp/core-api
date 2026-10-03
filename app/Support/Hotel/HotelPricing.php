<?php

namespace App\Support\Hotel;

use App\Models\RoomType;
use Illuminate\Support\Carbon;

/**
 * What a stay costs: each night at the room type's rate - its weekend rate
 * on Friday and Saturday nights, the Bangladeshi weekend, when it has one -
 * plus extras, less any discount, never below nothing. Night by night, so
 * the booking can show (and keep) exactly what was charged.
 */
final class HotelPricing
{
    /** Nights charged at the weekend rate: Friday and Saturday (Carbon: 5, 6). */
    public const WEEKEND_NIGHTS = [Carbon::FRIDAY, Carbon::SATURDAY];

    /**
     * @param  list<array{label: string, amount: float|int}>  $extras
     * @return array{nights: int, nightly_rates: list<array{date: string, rate: float}>, room_amount: float, extras_amount: float, discount: float, total: float}
     */
    public static function quote(RoomType $type, Carbon $checkIn, Carbon $checkOut, array $extras = [], float $discount = 0): array
    {
        $rates = [];
        for ($night = $checkIn->copy(); $night->lt($checkOut); $night->addDay()) {
            $weekend = $type->weekend_rate !== null && in_array($night->dayOfWeek, self::WEEKEND_NIGHTS, true);
            $rates[] = ['date' => $night->format('Y-m-d'), 'rate' => round((float) ($weekend ? $type->weekend_rate : $type->rate), 2)];
        }

        $room = array_sum(array_column($rates, 'rate'));
        $extrasAmount = array_sum(array_map(fn ($e) => (float) $e['amount'], $extras));
        $subtotal = $room + $extrasAmount;

        return [
            'nights' => count($rates),
            'nightly_rates' => $rates,
            'room_amount' => round($room, 2),
            'extras_amount' => round($extrasAmount, 2),
            'discount' => round(min($discount, $subtotal), 2),
            'total' => round(max(0, $subtotal - $discount), 2),
        ];
    }
}
