<?php

namespace App\Http\Controllers\Venues;

use App\Http\Controllers\Controller;
use App\Jobs\Venues\EmailVenueGuest;
use App\Models\Customer;
use App\Models\Hall;
use App\Models\VenueBooking;
use App\Models\VenueBookingPayment;
use App\Models\VenuePackage;
use App\Rules\PhoneNumber;
use App\Support\PhoneNumber as Phone;
use App\Support\Venues\VenuePricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Venue bookings: events in the restaurant's halls.
 *
 * A booking is priced on the server (VenuePricing) and the price copied onto
 * it. A Confirmed booking holds its hall from start to end plus the hall's
 * cleanup time; nothing may overlap it. A Pending one is tentative: overlaps
 * with it are allowed but reported, so staff see the competing enquiry.
 * Payments - the advance and the rest - are recorded against it, and the
 * balance due is always shown. Behind module:crm and manage_venue_bookings.
 */
class VenueBookingController extends Controller
{
    /**
     * GET /venue-bookings?view=upcoming|pending|past|all&from=&to=&hall_id=&location_id=&q=
     *
     * from/to (Y-m-d) give a calendar range instead of a view.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate([
            'view' => ['nullable', Rule::in(['upcoming', 'pending', 'past', 'all'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'hall_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $tz = config('app.timezone');
        $today = now($tz)->startOfDay();

        $query = VenueBooking::with(['hall:id,name,location_id', 'location:id,name'])
            ->withSum('payments as paid', 'amount')
            ->when($v['hall_id'] ?? null, fn ($q, $id) => $q->where('hall_id', $id))
            ->when($v['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->when($v['q'] ?? null, function ($q, $search) {
                $digits = preg_replace('/\D+/', '', $search);
                $q->where(fn ($w) => $w->where('guest_name', 'like', "%{$search}%")
                    ->orWhere('event_title', 'like', "%{$search}%")
                    ->when(strlen($digits) >= 3, fn ($w) => $w->orWhere('guest_phone', 'like', '%'.substr($digits, -9).'%')));
            });

        if (isset($v['from'])) {
            $from = Carbon::createFromFormat('Y-m-d', $v['from'], $tz)->startOfDay();
            $to = Carbon::createFromFormat('Y-m-d', $v['to'] ?? $v['from'], $tz)->endOfDay();
            $query->where('starts_at', '<', $to)->where('ends_at', '>', $from)->orderBy('starts_at');
        } else {
            match ($v['view'] ?? 'upcoming') {
                'pending' => $query->where('status', VenueBooking::PENDING)->orderBy('starts_at'),
                'upcoming' => $query->whereIn('status', [VenueBooking::PENDING, VenueBooking::CONFIRMED])->where('ends_at', '>=', $today)->orderBy('starts_at'),
                'past' => $query->where(fn ($q) => $q->where('ends_at', '<', $today)->orWhereIn('status', [VenueBooking::COMPLETED, VenueBooking::CANCELLED, VenueBooking::DECLINED]))->orderByDesc('starts_at'),
                default => $query->orderByDesc('starts_at'),
            };
        }

        return response()->json([
            'data' => $query->limit(300)->get()->map(fn (VenueBooking $b) => $this->summary($b)),
            'counts' => [
                'pending' => VenueBooking::where('status', VenueBooking::PENDING)->count(),
                'upcoming' => VenueBooking::whereIn('status', [VenueBooking::PENDING, VenueBooking::CONFIRMED])->where('ends_at', '>=', $today)->count(),
            ],
        ]);
    }

    /**
     * POST /venue-bookings/quote - the price, and what it would clash with,
     * for a booking being filled in. Saves nothing.
     */
    public function quote(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate($this->pricingRules() + ['booking_id' => ['nullable', 'integer']]);
        [$hall, $start, $end, $package] = $this->resolve($v);

        return response()->json([
            'price' => VenuePricing::quote($hall, $start, $end, (int) $v['guests'], $package, $v['extras'] ?? [], (float) ($v['discount'] ?? 0)),
            'clashes' => $this->clashes($hall, $start, $end, $v['booking_id'] ?? null),
            'warnings' => $this->warnings($hall, (int) $v['guests'], $package),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate($this->pricingRules() + $this->guestRules('required') + [
            'status' => ['nullable', Rule::in([VenueBooking::PENDING, VenueBooking::CONFIRMED])],
        ]);
        [$hall, $start, $end, $package] = $this->resolve($v);

        if ($start->lt(now()->subHours(12))) {
            throw ValidationException::withMessages(['date' => ['That time has already passed.']]);
        }

        $status = $v['status'] ?? VenueBooking::PENDING;
        if ($status === VenueBooking::CONFIRMED) {
            $this->assertFree($hall, $start, $end);
        }

        $phone = Phone::normalise($v['phone']) ?? $v['phone'];
        $customer = Customer::firstOrCreate(['phone' => $phone], ['name' => $v['name']]);
        $price = VenuePricing::quote($hall, $start, $end, (int) $v['guests'], $package, $v['extras'] ?? [], (float) ($v['discount'] ?? 0));

        $booking = VenueBooking::create([
            'location_id' => $hall->location_id,
            'hall_id' => $hall->id,
            'customer_id' => $customer->id,
            'guest_name' => $v['name'],
            'guest_phone' => $phone,
            'guest_email' => isset($v['email']) ? mb_strtolower(trim($v['email'])) : null,
            'event_type' => $v['event_type'],
            'event_title' => $v['event_title'] ?? null,
            'starts_at' => $start,
            'ends_at' => $end,
            'guests' => $v['guests'],
            'package_id' => $package?->id,
            'package_name' => $price['package_name'],
            'package_price' => $price['package_price'],
            'rent_amount' => $price['rent_amount'],
            'extras' => array_values($v['extras'] ?? []),
            'discount' => $price['discount'],
            'total' => $price['total'],
            'status' => $status,
            'source' => 'staff',
            'notes' => $v['notes'] ?? null,
            'handled_by' => $status === VenueBooking::CONFIRMED ? $request->user()->getKey() : null,
            'handled_at' => $status === VenueBooking::CONFIRMED ? now() : null,
        ]);

        // Taken by phone and confirmed on the spot: the guest gets it in writing.
        if ($status === VenueBooking::CONFIRMED) {
            EmailVenueGuest::notify($booking, 'confirmed');
        }

        return response()->json($this->detail($booking), 201);
    }

    public function show(Request $request, VenueBooking $venueBooking): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json($this->detail($venueBooking));
    }

    /**
     * PUT /venue-bookings/{id} - the details and price, re-worked out. A
     * confirmed booking moved onto a taken slot is refused.
     */
    public function update(Request $request, VenueBooking $venueBooking): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate($this->pricingRules() + $this->guestRules('sometimes'));
        [$hall, $start, $end, $package] = $this->resolve($v);

        if ($venueBooking->status === VenueBooking::CONFIRMED) {
            $this->assertFree($hall, $start, $end, $venueBooking->id);
        }

        // The package price agreed stays, unless the package itself changed.
        $keepPackagePrice = $package && $package->id === $venueBooking->package_id;
        $price = VenuePricing::quote($hall, $start, $end, (int) $v['guests'], $package, $v['extras'] ?? [], (float) ($v['discount'] ?? 0));
        if ($keepPackagePrice) {
            $food = $venueBooking->package_price * (int) $v['guests'];
            $price['package_price'] = $venueBooking->package_price;
            $price['total'] = round(max(0, $price['rent_amount'] + $food + $price['extras_amount'] - $price['discount']), 2);
        }

        $changes = [
            'location_id' => $hall->location_id,
            'hall_id' => $hall->id,
            'event_type' => $v['event_type'],
            'starts_at' => $start,
            'ends_at' => $end,
            'guests' => $v['guests'],
            'package_id' => $package?->id,
            'package_name' => $package ? ($keepPackagePrice ? $venueBooking->package_name : $price['package_name']) : null,
            'package_price' => $price['package_price'],
            'rent_amount' => $price['rent_amount'],
            'extras' => array_values($v['extras'] ?? []),
            'discount' => $price['discount'],
            'total' => $price['total'],
        ];
        foreach (['event_title', 'notes'] as $field) {
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

        $venueBooking->update($changes);

        return response()->json($this->detail($venueBooking->fresh()));
    }

    /** POST /venue-bookings/{id}/status - confirm, decline, complete, cancel, reopen. */
    public function status(Request $request, VenueBooking $venueBooking): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate([
            'status' => ['required', Rule::in(array_keys(VenueBooking::TRANSITIONS))],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);
        $to = $v['status'];

        if ($to !== $venueBooking->status && ! $venueBooking->canMoveTo($to)) {
            throw ValidationException::withMessages(['status' => ["A {$venueBooking->status} booking cannot be marked {$to}."]]);
        }
        if ($to === VenueBooking::CONFIRMED) {
            $this->assertFree($venueBooking->hall, $venueBooking->starts_at, $venueBooking->ends_at, $venueBooking->id);
        }

        $changes = ['status' => $to];
        if (in_array($to, [VenueBooking::CONFIRMED, VenueBooking::DECLINED], true)) {
            $changes += ['handled_by' => $request->user()->getKey(), 'handled_at' => now()];
        }
        if (in_array($to, [VenueBooking::DECLINED, VenueBooking::CANCELLED], true)) {
            $changes['decline_reason'] = $v['reason'] ?? null;
        }
        if ($to === VenueBooking::PENDING) {
            $changes += ['decline_reason' => null, 'handled_by' => null, 'handled_at' => null];
        }

        $from = $venueBooking->status;
        $venueBooking->update($changes);

        // The guest hears about the moves that change things for them.
        if ($from !== $to && in_array($to, [VenueBooking::CONFIRMED, VenueBooking::DECLINED, VenueBooking::CANCELLED], true)) {
            EmailVenueGuest::notify($venueBooking, strtolower($to));
        }

        return response()->json($this->detail($venueBooking->fresh()));
    }

    /**
     * POST /venue-bookings/{id}/email - sends the guest the quotation (a
     * tentative booking) or the confirmation (a confirmed one) now.
     */
    public function email(Request $request, VenueBooking $venueBooking): JsonResponse
    {
        $this->authorizeManage($request);

        if (! $venueBooking->guest_email) {
            throw ValidationException::withMessages(['email' => ['Add the guest\'s email to the booking first.']]);
        }
        if (! in_array($venueBooking->status, [VenueBooking::PENDING, VenueBooking::CONFIRMED, VenueBooking::COMPLETED], true)) {
            throw ValidationException::withMessages(['email' => ['There is nothing to send for a '.strtolower($venueBooking->status).' booking.']]);
        }

        EmailVenueGuest::notify($venueBooking, $venueBooking->status === VenueBooking::PENDING ? 'quotation' : 'confirmed');

        return response()->json(['queued' => true, 'to' => $venueBooking->guest_email]);
    }

    /** POST /venue-bookings/{id}/called */
    public function called(Request $request, VenueBooking $venueBooking): JsonResponse
    {
        $this->authorizeManage($request);

        $venueBooking->update(['called_at' => now(), 'called_by' => $request->user()->getKey()]);

        return response()->json($this->detail($venueBooking->fresh()));
    }

    /** POST /venue-bookings/{id}/payments - the advance, or a later payment. */
    public function addPayment(Request $request, VenueBooking $venueBooking): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'method' => ['required', Rule::in(VenueBookingPayment::METHODS)],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $due = $venueBooking->total - (float) $venueBooking->payments()->sum('amount');
        if ($v['amount'] > $due + 0.001) {
            throw ValidationException::withMessages(['amount' => ['That is more than the '.number_format(max(0, $due), 2).' still due.']]);
        }

        $venueBooking->payments()->create($v + ['paid_at' => now(), 'received_by' => $request->user()->getKey()]);
        // A receipt, with what is still due.
        EmailVenueGuest::notify($venueBooking, 'payment');

        return response()->json($this->detail($venueBooking->fresh()), 201);
    }

    /** DELETE /venue-bookings/{id}/payments/{payment} - a payment recorded by mistake. */
    public function removePayment(Request $request, VenueBooking $venueBooking, VenueBookingPayment $payment): JsonResponse
    {
        $this->authorizeManage($request);

        abort_unless($payment->venue_booking_id === $venueBooking->id, 404);
        $payment->delete();

        return response()->json($this->detail($venueBooking->fresh()));
    }

    public function destroy(Request $request, VenueBooking $venueBooking): JsonResponse
    {
        $this->authorizeManage($request);

        if ($venueBooking->payments()->exists()) {
            abort(422, 'This booking has payments recorded. Cancel it instead, so the money stays accounted for.');
        }

        $venueBooking->delete();

        return response()->json(null, 204);
    }

    // ---- helpers ---------------------------------------------------------------------

    /** @return array<string, list<mixed>> */
    private function pricingRules(): array
    {
        return [
            'hall_id' => ['required', 'integer', $this->tenantExists('halls')],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            // An event that runs past midnight ends the next day.
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'guests' => ['required', 'integer', 'min:1', 'max:5000'],
            'event_type' => ['required', Rule::in(VenueBooking::EVENT_TYPES)],
            'package_id' => ['nullable', 'integer', $this->tenantExists('venue_packages')],
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
            // The number staff call - always required, never verified.
            'phone' => [$required, 'string', 'max:20', PhoneNumber::mobile()],
            // Where booking emails go: confirmed, declined, payments.
            'email' => ['nullable', 'email:rfc', 'max:191'],
            'event_title' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array{0: Hall, 1: Carbon, 2: Carbon, 3: ?VenuePackage} */
    private function resolve(array $v): array
    {
        $tz = config('app.timezone');
        $hall = Hall::findOrFail($v['hall_id']);
        $start = Carbon::createFromFormat('Y-m-d H:i', $v['date'].' '.$v['start_time'], $tz);
        $end = Carbon::createFromFormat('Y-m-d H:i', ($v['end_date'] ?? $v['date']).' '.$v['end_time'], $tz);

        // "18:00 to 01:00" on one date: it ends after midnight.
        if (! isset($v['end_date']) && $end->lte($start)) {
            $end->addDay();
        }
        if ($end->lte($start)) {
            throw ValidationException::withMessages(['end_time' => ['The event must end after it starts.']]);
        }
        if ($start->diffInHours($end) > 72) {
            throw ValidationException::withMessages(['end_time' => ['An event can last up to 3 days.']]);
        }

        $package = isset($v['package_id']) ? VenuePackage::find($v['package_id']) : null;

        return [$hall, $start, $end, $package];
    }

    /** Refuses a slot a confirmed booking already holds. */
    private function assertFree(Hall $hall, Carbon $start, Carbon $end, ?int $except = null): void
    {
        $taken = VenueBooking::clashingWith($hall, $start, $end, $except)->where('status', VenueBooking::CONFIRMED)->orderBy('starts_at')->first();

        if ($taken) {
            $tz = config('app.timezone');
            throw ValidationException::withMessages(['hall_id' => [sprintf(
                '%s is booked %s - %s for %s (%s). %d minutes are kept free after each event for cleanup.',
                $hall->name,
                $taken->starts_at->setTimezone($tz)->format('j M g:i A'),
                $taken->ends_at->setTimezone($tz)->format('g:i A'),
                $taken->guest_name,
                $taken->event_title ?: ucfirst($taken->event_type),
                $hall->buffer_minutes ?? 0,
            )]]);
        }
    }

    /** @return list<array<string, mixed>> other bookings in the way, confirmed or tentative */
    private function clashes(Hall $hall, Carbon $start, Carbon $end, ?int $except): array
    {
        return VenueBooking::clashingWith($hall, $start, $end, $except)
            ->whereIn('status', [VenueBooking::PENDING, VenueBooking::CONFIRMED])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (VenueBooking $b) => $this->summary($b))
            ->all();
    }

    /** @return list<string> */
    private function warnings(Hall $hall, int $guests, ?VenuePackage $package): array
    {
        return array_values(array_filter([
            $hall->capacity && $guests > $hall->capacity ? "{$hall->name} holds up to {$hall->capacity} guests." : null,
            $hall->min_guests && $guests < $hall->min_guests ? "{$hall->name} is for at least {$hall->min_guests} guests." : null,
            $package?->min_guests && $guests < $package->min_guests ? "{$package->name} is for at least {$package->min_guests} guests." : null,
            ! ($hall->is_active ?? true) ? "{$hall->name} is switched off for new bookings." : null,
        ]));
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can('manage_venue_bookings')) {
            abort(403, 'You do not have permission to manage venue bookings.');
        }
    }

    /** @return array<string, mixed> */
    private function summary(VenueBooking $b): array
    {
        $tz = config('app.timezone');
        $paid = round((float) ($b->paid ?? $b->payments()->sum('amount')), 2);

        return [
            'id' => $b->id,
            'status' => $b->status,
            'next' => VenueBooking::TRANSITIONS[$b->status] ?? [],
            'guest_name' => $b->guest_name,
            'guest_phone' => $b->guest_phone,
            'guest_email' => $b->guest_email,
            'event_type' => $b->event_type,
            'event_title' => $b->event_title,
            'guests' => $b->guests,
            'hall' => $b->hall ? ['id' => $b->hall->id, 'name' => $b->hall->name] : null,
            'location' => $b->location ? ['id' => $b->location->id, 'name' => $b->location->name] : null,
            // In the restaurant's own time, whatever the browser's.
            'date' => $b->starts_at->copy()->setTimezone($tz)->format('Y-m-d'),
            'start_time' => $b->starts_at->copy()->setTimezone($tz)->format('H:i'),
            'end_date' => $b->ends_at->copy()->setTimezone($tz)->format('Y-m-d'),
            'end_time' => $b->ends_at->copy()->setTimezone($tz)->format('H:i'),
            'total' => (float) $b->total,
            'paid' => $paid,
            'due' => round(max(0, $b->total - $paid), 2),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(VenueBooking $b): array
    {
        $b->loadMissing(['hall', 'location:id,name', 'payments.receiver:id,name', 'handler:id,name', 'caller:id,name']);
        $b->paid = $b->payments->sum('amount');
        $extras = $b->extras ?? [];

        return $this->summary($b) + [
            'package' => $b->package_id || $b->package_name ? ['id' => $b->package_id, 'name' => $b->package_name, 'price_per_person' => (float) $b->package_price] : null,
            'rent_amount' => (float) $b->rent_amount,
            'food_amount' => round($b->package_price * $b->guests, 2),
            'extras' => $extras,
            'extras_amount' => round(array_sum(array_column($extras, 'amount')), 2),
            'discount' => (float) $b->discount,
            'notes' => $b->notes,
            'source' => $b->source,
            'decline_reason' => $b->decline_reason,
            'handled_by' => $b->handler?->name,
            'handled_at' => $b->handled_at?->toIso8601String(),
            'called_at' => $b->called_at?->toIso8601String(),
            'called_by' => $b->caller?->name,
            'last_emailed_at' => $b->last_emailed_at?->toIso8601String(),
            'payments' => $b->payments->map(fn (VenueBookingPayment $p) => [
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
