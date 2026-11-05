<?php

namespace App\Support\Hotel;

use App\Models\Room;
use App\Models\RoomBooking;
use App\Models\RoomType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How many rooms of a type are free, night by night.
 *
 * A night's free count is the type's rooms in service less the stays that
 * hold a room that night (Confirmed or Checked in) - so two guests in the
 * same room on different nights never block each other, and a stay ending
 * the morning another begins is fine. Pending requests hold nothing; they
 * are counted separately so staff can see the demand.
 *
 * Runs in the restaurant's tenant context.
 */
final class HotelAvailability
{
    /**
     * @return array<string, array{free: int, pending: int}> per night (Y-m-d) of [from, to)
     */
    public static function nights(RoomType $type, Carbon $from, Carbon $to, ?int $exceptBooking = null): array
    {
        $rooms = Room::where('room_type_id', $type->id)->where('in_service', true)->count();
        $stays = RoomBooking::where('room_type_id', $type->id)
            ->overlapping($from->format('Y-m-d'), $to->format('Y-m-d'))
            ->whereIn('status', [...RoomBooking::HOLDING, RoomBooking::PENDING])
            ->when($exceptBooking, fn ($q) => $q->whereKeyNot($exceptBooking))
            ->get(['check_in', 'check_out', 'status']);

        $nights = [];
        for ($night = $from->copy(); $night->lt($to); $night->addDay()) {
            $tonight = $stays->filter(fn ($s) => $s->check_in->lte($night) && $s->check_out->gt($night));
            $nights[$night->format('Y-m-d')] = [
                'free' => max(0, $rooms - $tonight->whereIn('status', RoomBooking::HOLDING)->count()),
                'pending' => $tonight->where('status', RoomBooking::PENDING)->count(),
            ];
        }

        return $nights;
    }

    /** Rooms free for the whole stay - the fewest on any night. */
    public static function free(RoomType $type, Carbon $from, Carbon $to, ?int $exceptBooking = null): int
    {
        $nights = self::nights($type, $from, $to, $exceptBooking);

        return $nights === [] ? 0 : min(array_column($nights, 'free'));
    }

    /** The first night with no room free, or null when every night has one. */
    public static function fullNight(RoomType $type, Carbon $from, Carbon $to, ?int $exceptBooking = null): ?string
    {
        foreach (self::nights($type, $from, $to, $exceptBooking) as $date => $night) {
            if ($night['free'] < 1) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Rooms of the type a stay could be put in: in service, and not given to
     * another stay holding a room on any of its nights.
     *
     * @return Collection<int, Room>
     */
    public static function assignableRooms(RoomBooking $booking)
    {
        $taken = RoomBooking::where('room_type_id', $booking->room_type_id)
            ->whereKeyNot($booking->id)
            ->whereNotNull('room_id')
            ->whereIn('status', RoomBooking::HOLDING)
            ->overlapping($booking->check_in->format('Y-m-d'), $booking->check_out->format('Y-m-d'))
            ->pluck('room_id');

        return Room::where('room_type_id', $booking->room_type_id)
            ->where('in_service', true)
            ->whereNotIn('id', $taken)
            ->orderBy('number')
            ->get();
    }
}
