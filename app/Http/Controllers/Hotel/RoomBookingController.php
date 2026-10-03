<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Jobs\Hotel\EmailRoomGuest;
use App\Models\Customer;
use App\Models\Room;
use App\Models\RoomBooking;
use App\Models\RoomBookingPayment;
use App\Models\RoomType;
use App\Rules\PhoneNumber;
use App\Support\Hotel\HotelAvailability;
use App\Support\Hotel\HotelPricing;
use App\Support\PhoneNumber as Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Room bookings: the front desk.
 *
 * Requests from the website arrive Pending with the guest's phone. Staff
 * confirm them - only while a room of the type is free every night of the
 * stay - assign a room by check-in, take payments, and check the guest out.
 * Staff take bookings by phone or at the desk too. Behind module:hotel and
 * manage_hotel.
 */
class RoomBookingController extends Controller
{
    /** Longest stay the desk or the website takes in one booking. */
    public const MAX_NIGHTS = 60;

    /**
     * GET /hotel/bookings?view=arrivals|in_house|departures|upcoming|pending|past|all&q=
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate([
            'view' => ['nullable', Rule::in(['arrivals', 'in_house', 'departures', 'upcoming', 'pending', 'past', 'all'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $today = $this->today();

        $query = RoomBooking::with(['roomType:id,name', 'room:id,number'])
            ->withSum('payments as paid', 'amount')
            ->when($v['q'] ?? null, function ($q, $search) {
                $digits = preg_replace('/\D+/', '', $search);
                $q->where(fn ($w) => $w->where('guest_name', 'like', "%{$search}%")
                    ->orWhere('guest_email', 'like', "%{$search}%")
                    ->when(strlen($digits) >= 3, fn ($w) => $w->orWhere('guest_phone', 'like', '%'.substr($digits, -9).'%')));
            });

        match ($v['view'] ?? 'arrivals') {
            'arrivals' => $query->where('check_in', $today)->whereIn('status', [RoomBooking::CONFIRMED, RoomBooking::PENDING])->orderBy('guest_name'),
            'in_house' => $query->where('status', RoomBooking::CHECKED_IN)->orderBy('check_out'),
            'departures' => $query->where('check_out', $today)->where('status', RoomBooking::CHECKED_IN)->orderBy('guest_name'),
            'upcoming' => $query->where('check_in', '>=', $today)->whereIn('status', [RoomBooking::CONFIRMED, RoomBooking::PENDING])->orderBy('check_in'),
            'pending' => $query->where('status', RoomBooking::PENDING)->orderBy('check_in'),
            'past' => $query->where(fn ($q) => $q->where('check_out', '<', $today)->orWhereIn('status', [RoomBooking::CHECKED_OUT, RoomBooking::CANCELLED, RoomBooking::DECLINED, RoomBooking::NO_SHOW]))->orderByDesc('check_in'),
            default => $query->orderByDesc('check_in'),
        };

        return response()->json([
            'data' => $query->limit(300)->get()->map(fn (RoomBooking $b) => $this->summary($b)),
            'counts' => [
                'arrivals' => RoomBooking::where('check_in', $today)->whereIn('status', [RoomBooking::CONFIRMED, RoomBooking::PENDING])->count(),
                'in_house' => RoomBooking::where('status', RoomBooking::CHECKED_IN)->count(),
                'departures' => RoomBooking::where('check_out', $today)->where('status', RoomBooking::CHECKED_IN)->count(),
                'pending' => RoomBooking::where('status', RoomBooking::PENDING)->count(),
            ],
        ]);
    }

    /** GET /hotel/availability?from=&days= - free rooms per type per night, for the grid. */
    public function availability(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'days' => ['nullable', 'integer', 'min:1', 'max:31']]);
        $from = isset($v['from']) ? Carbon::createFromFormat('Y-m-d', $v['from'], config('app.timezone'))->startOfDay() : Carbon::parse($this->today(), config('app.timezone'));
        $to = $from->copy()->addDays((int) ($v['days'] ?? 14));

        return response()->json([
            'from' => $from->format('Y-m-d'),
            'types' => RoomType::withCount(['rooms as rooms_in_service' => fn ($q) => $q->where('in_service', true)])
                ->where('is_active', true)->orderBy('sort_order')->orderBy('rate')->get()
                ->map(fn (RoomType $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'rooms' => $t->rooms_in_service,
                    'nights' => HotelAvailability::nights($t, $from, $to),
                ]),
        ]);
    }

    /** POST /hotel/bookings/quote - price, rooms free and warnings for a stay being filled in. Saves nothing. */
    public function quote(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate($this->stayRules() + ['booking_id' => ['nullable', 'integer']]);
        [$type, $in, $out] = $this->resolve($v);

        return response()->json([
            'price' => HotelPricing::quote($type, $in, $out, $v['extras'] ?? [], (float) ($v['discount'] ?? 0)),
            'free' => HotelAvailability::free($type, $in, $out, $v['booking_id'] ?? null),
            'full_night' => HotelAvailability::fullNight($type, $in, $out, $v['booking_id'] ?? null),
            'warnings' => $this->warnings($type, (int) $v['adults'], (int) ($v['children'] ?? 0)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate($this->stayRules() + $this->guestRules('required') + [
            'status' => ['nullable', Rule::in([RoomBooking::PENDING, RoomBooking::CONFIRMED])],
        ]);
        [$type, $in, $out] = $this->resolve($v);

        if ($in->lt(Carbon::parse($this->today(), config('app.timezone'))->subDay())) {
            throw ValidationException::withMessages(['check_in' => ['That date has already passed.']]);
        }

        $status = $v['status'] ?? RoomBooking::CONFIRMED;
        if ($status === RoomBooking::CONFIRMED) {
            $this->assertFree($type, $in, $out);
        }

        $phone = Phone::normalise($v['phone']) ?? $v['phone'];
        $customer = Customer::firstOrCreate(['phone' => $phone], ['name' => $v['name']]);
        $price = HotelPricing::quote($type, $in, $out, $v['extras'] ?? [], (float) ($v['discount'] ?? 0));

        $booking = RoomBooking::create([
            'location_id' => $type->location_id,
            'room_type_id' => $type->id,
            'customer_id' => $customer->id,
            'guest_name' => $v['name'],
            'guest_phone' => $phone,
            'guest_email' => isset($v['email']) ? mb_strtolower(trim($v['email'])) : null,
            'check_in' => $in->format('Y-m-d'),
            'check_out' => $out->format('Y-m-d'),
            'adults' => $v['adults'],
            'children' => $v['children'] ?? 0,
            'nightly_rates' => $price['nightly_rates'],
            'room_amount' => $price['room_amount'],
            'extras' => array_values($v['extras'] ?? []),
            'discount' => $price['discount'],
            'total' => $price['total'],
            'status' => $status,
            'source' => 'staff',
            'notes' => $v['notes'] ?? null,
            'handled_by' => $status === RoomBooking::CONFIRMED ? $request->user()->getKey() : null,
            'handled_at' => $status === RoomBooking::CONFIRMED ? now() : null,
        ]);

        if ($status === RoomBooking::CONFIRMED) {
            EmailRoomGuest::notify($booking, 'confirmed');
        }

        return response()->json($this->detail($booking), 201);
    }

    public function show(Request $request, RoomBooking $roomBooking): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json($this->detail($roomBooking));
    }

    /**
     * PUT /hotel/bookings/{id} - dates, guests, room type, extras, guest. A
     * booking holding a room may only move where a room is free; the price
     * is worked out again from today's rates.
     */
    public function update(Request $request, RoomBooking $roomBooking): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate($this->stayRules() + $this->guestRules('sometimes'));
        [$type, $in, $out] = $this->resolve($v);

        $holding = in_array($roomBooking->status, RoomBooking::HOLDING, true);
        if ($holding) {
            $this->assertFree($type, $in, $out, $roomBooking->id);
        }

        $price = HotelPricing::quote($type, $in, $out, $v['extras'] ?? [], (float) ($v['discount'] ?? 0));
        $changes = [
            'location_id' => $type->location_id,
            'room_type_id' => $type->id,
            'check_in' => $in->format('Y-m-d'),
            'check_out' => $out->format('Y-m-d'),
            'adults' => $v['adults'],
            'children' => $v['children'] ?? 0,
            'nightly_rates' => $price['nightly_rates'],
            'room_amount' => $price['room_amount'],
            'extras' => array_values($v['extras'] ?? []),
            'discount' => $price['discount'],
            'total' => $price['total'],
        ];
        // A different type, or a room now taken on the new nights: unassigned.
        if ($roomBooking->room_id && ($type->id !== $roomBooking->room_type_id || ! $this->roomFreeFor($roomBooking->room_id, $roomBooking->id, $in, $out))) {
            $changes['room_id'] = null;
        }
        foreach (['notes'] as $field) {
            if (array_key_exists($field, $v)) {
                $changes[$field] = $v[$field];
            }
        }
        if (isset($v['name'])) {
            $changes['guest_name'] = $v['name'];
        }
        if (isset($v['phone'])) {
            $changes['guest_phone'] = Phone::normalise($v['phone']) ?? $v['phone'];
        }
        if (array_key_exists('email', $v)) {
            $changes['guest_email'] = $v['email'] ? mb_strtolower(trim($v['email'])) : null;
        }

        $roomBooking->update($changes);

        return response()->json($this->detail($roomBooking->fresh()));
    }

    /**
     * POST /hotel/bookings/{id}/status - confirm, decline, check in (with a
     * room), check out, no-show, cancel, reopen.
     */
    public function status(Request $request, RoomBooking $roomBooking): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate([
            'status' => ['required', Rule::in(array_keys(RoomBooking::TRANSITIONS))],
            'reason' => ['nullable', 'string', 'max:300'],
            'room_id' => ['nullable', 'integer'],
        ]);
        $to = $v['status'];
        $from = $roomBooking->status;

        if ($to === $from) {
            return response()->json($this->detail($roomBooking));
        }
        if (! $roomBooking->canMoveTo($to)) {
            throw ValidationException::withMessages(['status' => ["A {$from} booking cannot be marked {$to}."]]);
        }

        $changes = ['status' => $to];

        if (in_array($to, RoomBooking::HOLDING, true) && ! in_array($from, RoomBooking::HOLDING, true)) {
            $this->assertFree($roomBooking->roomType, $roomBooking->check_in, $roomBooking->check_out, $roomBooking->id);
        }
        if ($to === RoomBooking::CONFIRMED && $from === RoomBooking::PENDING) {
            $changes += ['handled_by' => $request->user()->getKey(), 'handled_at' => now()];
        }
        if ($to === RoomBooking::DECLINED) {
            $changes += ['handled_by' => $request->user()->getKey(), 'handled_at' => now()];
        }
        if (in_array($to, [RoomBooking::DECLINED, RoomBooking::CANCELLED], true)) {
            $changes['decline_reason'] = $v['reason'] ?? null;
        }
        if ($to === RoomBooking::PENDING) {
            $changes += ['decline_reason' => null, 'handled_by' => null, 'handled_at' => null, 'room_id' => null];
        }
        if ($to === RoomBooking::CHECKED_IN) {
            $roomId = $v['room_id'] ?? $roomBooking->room_id;
            if (! $roomId) {
                throw ValidationException::withMessages(['room_id' => ['Choose the room the guest is staying in.']]);
            }
            $this->assertRoomFor($roomBooking, $roomId);
            $changes += ['room_id' => $roomId, 'checked_in_at' => now()];
        }
        if ($to === RoomBooking::CHECKED_OUT) {
            $changes['checked_out_at'] = now();
        }
        if (in_array($to, [RoomBooking::CANCELLED, RoomBooking::NO_SHOW, RoomBooking::DECLINED], true)) {
            $changes['room_id'] = null;
        }

        $roomBooking->update($changes);

        if (in_array($to, [RoomBooking::CONFIRMED, RoomBooking::DECLINED, RoomBooking::CANCELLED], true) && $from !== RoomBooking::CHECKED_IN) {
            EmailRoomGuest::notify($roomBooking, strtolower($to));
        }

        return response()->json($this->detail($roomBooking->fresh()));
    }

    /** GET /hotel/bookings/{id}/rooms - the rooms this stay could go in. */
    public function rooms(Request $request, RoomBooking $roomBooking): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json(['rooms' => HotelAvailability::assignableRooms($roomBooking)->map(fn (Room $r) => ['id' => $r->id, 'number' => $r->number, 'floor' => $r->floor])->values()]);
    }

    /** POST /hotel/bookings/{id}/room - assigns (or changes) the room. */
    public function assignRoom(Request $request, RoomBooking $roomBooking): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate(['room_id' => ['nullable', 'integer']]);

        if (! in_array($roomBooking->status, RoomBooking::HOLDING, true)) {
            throw ValidationException::withMessages(['room_id' => ['Confirm the booking before giving it a room.']]);
        }
        if ($v['room_id'] ?? null) {
            $this->assertRoomFor($roomBooking, $v['room_id']);
        } elseif ($roomBooking->status === RoomBooking::CHECKED_IN) {
            throw ValidationException::withMessages(['room_id' => ['A checked-in guest needs a room.']]);
        }

        $roomBooking->update(['room_id' => $v['room_id'] ?? null]);

        return response()->json($this->detail($roomBooking->fresh()));
    }

    public function addPayment(Request $request, RoomBooking $roomBooking): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'method' => ['required', Rule::in(RoomBookingPayment::METHODS)],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $due = $roomBooking->total - (float) $roomBooking->payments()->sum('amount');
        if ($v['amount'] > $due + 0.001) {
            throw ValidationException::withMessages(['amount' => ['That is more than the '.number_format(max(0, $due), 2).' still due.']]);
        }

        $roomBooking->payments()->create($v + ['paid_at' => now(), 'received_by' => $request->user()->getKey()]);

        return response()->json($this->detail($roomBooking->fresh()), 201);
    }

    public function removePayment(Request $request, RoomBooking $roomBooking, RoomBookingPayment $payment): JsonResponse
    {
        $this->authorizeManage($request);

        abort_unless($payment->room_booking_id === $roomBooking->id, 404);
        $payment->delete();

        return response()->json($this->detail($roomBooking->fresh()));
    }

    /** POST /hotel/bookings/{id}/email - sends the guest the confirmation now. */
    public function email(Request $request, RoomBooking $roomBooking): JsonResponse
    {
        $this->authorizeManage($request);

        if (! $roomBooking->guest_email) {
            throw ValidationException::withMessages(['email' => ['Add the guest\'s email to the booking first.']]);
        }
        if (! in_array($roomBooking->status, [RoomBooking::PENDING, ...RoomBooking::HOLDING], true)) {
            throw ValidationException::withMessages(['email' => ['There is nothing to send for a '.strtolower($roomBooking->status).' booking.']]);
        }

        EmailRoomGuest::notify($roomBooking, $roomBooking->status === RoomBooking::PENDING ? 'received' : 'confirmed');

        return response()->json(['queued' => true, 'to' => $roomBooking->guest_email]);
    }

    public function destroy(Request $request, RoomBooking $roomBooking): JsonResponse
    {
        $this->authorizeManage($request);

        if ($roomBooking->payments()->exists()) {
            abort(422, 'This booking has payments recorded. Cancel it instead, so the money stays accounted for.');
        }

        $roomBooking->delete();

        return response()->json(null, 204);
    }

    // ---- helpers ---------------------------------------------------------------------

    private function today(): string
    {
        return now(config('app.timezone'))->format('Y-m-d');
    }

    /** @return array<string, list<mixed>> */
    private function stayRules(): array
    {
        return [
            'room_type_id' => ['required', 'integer', $this->tenantExists('room_types')],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'extras' => ['nullable', 'array', 'max:20'],
            'extras.*.label' => ['required', 'string', 'max:80'],
            'extras.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }

    /** @return array<string, list<mixed>> */
    private function guestRules(string $required): array
    {
        return [
            'name' => [$required, 'string', 'max:100'],
            // The guest is reached by phone - required, never verified.
            'phone' => [$required, 'string', 'max:20', PhoneNumber::mobile()],
            'email' => ['nullable', 'email:rfc', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array{0: RoomType, 1: Carbon, 2: Carbon} */
    private function resolve(array $v): array
    {
        $tz = config('app.timezone');
        $type = RoomType::findOrFail($v['room_type_id']);
        $in = Carbon::createFromFormat('Y-m-d', $v['check_in'], $tz)->startOfDay();
        $out = Carbon::createFromFormat('Y-m-d', $v['check_out'], $tz)->startOfDay();

        if ($in->diffInDays($out) > self::MAX_NIGHTS) {
            throw ValidationException::withMessages(['check_out' => ['A booking can be up to '.self::MAX_NIGHTS.' nights.']]);
        }

        return [$type, $in, $out];
    }

    private function assertFree(RoomType $type, Carbon $in, Carbon $out, ?int $except = null): void
    {
        $full = HotelAvailability::fullNight($type, $in, $out, $except);

        if ($full !== null) {
            throw ValidationException::withMessages(['room_type_id' => [
                "{$type->name} is fully booked on the night of ".Carbon::parse($full)->format('D j M').'.',
            ]]);
        }
    }

    private function assertRoomFor(RoomBooking $booking, int $roomId): void
    {
        $room = Room::whereKey($roomId)->first();

        if ($room === null || $room->room_type_id !== $booking->room_type_id) {
            throw ValidationException::withMessages(['room_id' => ['Choose a '.$booking->roomType->name.' room.']]);
        }
        if (! $room->in_service) {
            throw ValidationException::withMessages(['room_id' => ["Room {$room->number} is out of service."]]);
        }
        if (! $this->roomFreeFor($room->id, $booking->id, $booking->check_in, $booking->check_out)) {
            throw ValidationException::withMessages(['room_id' => ["Room {$room->number} is taken for some of these nights."]]);
        }
    }

    private function roomFreeFor(int $roomId, int $exceptBooking, Carbon $in, Carbon $out): bool
    {
        return ! RoomBooking::where('room_id', $roomId)
            ->whereKeyNot($exceptBooking)
            ->whereIn('status', RoomBooking::HOLDING)
            ->overlapping($in->format('Y-m-d'), $out->format('Y-m-d'))
            ->exists();
    }

    /** @return list<string> */
    private function warnings(RoomType $type, int $adults, int $children): array
    {
        return array_values(array_filter([
            $adults > $type->max_adults ? "{$type->name} sleeps up to {$type->max_adults} adults." : null,
            $children > $type->max_children ? "{$type->name} takes up to {$type->max_children} children." : null,
            ! $type->is_active ? "{$type->name} is switched off for new bookings." : null,
        ]));
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can('manage_hotel')) {
            abort(403, 'You do not have permission to manage the hotel.');
        }
    }

    /** @return array<string, mixed> */
    private function summary(RoomBooking $b): array
    {
        $paid = round((float) ($b->paid ?? $b->payments()->sum('amount')), 2);

        return [
            'id' => $b->id,
            'status' => $b->status,
            'next' => RoomBooking::TRANSITIONS[$b->status] ?? [],
            'guest_name' => $b->guest_name,
            'guest_phone' => $b->guest_phone,
            'guest_email' => $b->guest_email,
            'room_type' => $b->roomType ? ['id' => $b->roomType->id, 'name' => $b->roomType->name] : null,
            'room' => $b->room ? ['id' => $b->room->id, 'number' => $b->room->number] : null,
            'check_in' => $b->check_in->format('Y-m-d'),
            'check_out' => $b->check_out->format('Y-m-d'),
            'nights' => $b->nights(),
            'adults' => $b->adults,
            'children' => $b->children,
            'source' => $b->source,
            'total' => (float) $b->total,
            'paid' => $paid,
            'due' => round(max(0, $b->total - $paid), 2),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(RoomBooking $b): array
    {
        $b->loadMissing(['roomType', 'room', 'location:id,name', 'payments.receiver:id,name', 'handler:id,name']);
        $b->paid = $b->payments->sum('amount');
        $extras = $b->extras ?? [];

        return $this->summary($b) + [
            'location' => $b->location ? ['id' => $b->location->id, 'name' => $b->location->name] : null,
            'nightly_rates' => $b->nightly_rates ?? [],
            'room_amount' => (float) $b->room_amount,
            'extras' => $extras,
            'extras_amount' => round(array_sum(array_column($extras, 'amount')), 2),
            'discount' => (float) $b->discount,
            'notes' => $b->notes,
            'decline_reason' => $b->decline_reason,
            'handled_by' => $b->handler?->name,
            'handled_at' => $b->handled_at?->toIso8601String(),
            'checked_in_at' => $b->checked_in_at?->toIso8601String(),
            'checked_out_at' => $b->checked_out_at?->toIso8601String(),
            'last_emailed_at' => $b->last_emailed_at?->toIso8601String(),
            'payments' => $b->payments->map(fn (RoomBookingPayment $p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'note' => $p->note,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'received_by' => $p->receiver?->name,
            ])->values(),
            'created_at' => $b->created_at?->toIso8601String(),
        ];
    }
}
