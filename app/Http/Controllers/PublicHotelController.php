<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Hotel\HotelSetupController;
use App\Jobs\Hotel\EmailRoomGuest;
use App\Models\Customer;
use App\Models\RoomBooking;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Rules\PhoneNumber;
use App\Support\Billing\Modules;
use App\Support\Hotel\HotelAvailability;
use App\Support\Hotel\HotelPricing;
use App\Support\PhoneNumber as Phone;
use App\Support\Site\Conversion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A hotel's rooms on its website: what there is, what is free for some
 * dates and at what price, and a booking request - with the guest's phone
 * number (not verified) and, if they like, an email for confirmations.
 * Public, tenant from the URL, like the rest of the site.
 */
class PublicHotelController extends Controller
{
    /** Longest stay a guest can request online. */
    private const MAX_NIGHTS = 30;

    public function __construct(private TenantContext $context) {}

    /** GET /site/{code}/rooms?check_in=&check_out=&adults=&children= */
    public function rooms(Request $request, string $code): JsonResponse
    {
        $tenant = $this->hotel($code);

        return $this->context->runFor($tenant, function () use ($request) {
            $v = $request->validate([
                'check_in' => ['nullable', 'date_format:Y-m-d', 'required_with:check_out'],
                'check_out' => ['nullable', 'date_format:Y-m-d', 'after:check_in', 'required_with:check_in'],
                'adults' => ['nullable', 'integer', 'min:1', 'max:20'],
                'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            ]);

            $stay = isset($v['check_in']) ? $this->stay($v) : null;

            return response()->json([
                'room_types' => RoomType::with('location:id,name')->where('is_active', true)
                    ->whereHas('rooms', fn ($q) => $q->where('in_service', true))
                    ->orderBy('sort_order')->orderBy('rate')->get()
                    ->map(function (RoomType $t) use ($stay, $v) {
                        $row = HotelSetupController::presentType($t, withRooms: false);
                        if ($stay) {
                            [$in, $out] = $stay;
                            $fits = ($v['adults'] ?? 1) <= $t->max_adults && ($v['children'] ?? 0) <= $t->max_children;
                            $row['available'] = $fits && HotelAvailability::free($t, $in, $out) > 0;
                            $row['fits'] = $fits;
                            $row['price'] = HotelPricing::quote($t, $in, $out);
                        }

                        return $row;
                    })
                    ->values(),
            ]);
        });
    }

    /** POST /site/{code}/room-requests - a booking request, Pending, for the hotel to confirm. */
    public function request(Request $request, string $code): JsonResponse
    {
        $tenant = $this->hotel($code);

        return $this->context->runFor($tenant, function () use ($request) {
            $v = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                // How the hotel reaches the guest - required, not verified.
                'phone' => ['required', 'string', 'max:20', PhoneNumber::mobile()],
                'email' => ['nullable', 'email:rfc', 'max:191'],
                'room_type_id' => ['required', 'integer'],
                'check_in' => ['required', 'date_format:Y-m-d'],
                'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
                'adults' => ['required', 'integer', 'min:1', 'max:20'],
                'children' => ['nullable', 'integer', 'min:0', 'max:20'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ] + Conversion::REQUEST_RULES);

            $type = RoomType::where('is_active', true)->whereKey($v['room_type_id'])->first()
                ?? throw ValidationException::withMessages(['room_type_id' => ['Choose one of our rooms.']]);
            [$in, $out] = $this->stay($v);

            if ($v['adults'] > $type->max_adults || ($v['children'] ?? 0) > $type->max_children) {
                throw ValidationException::withMessages(['adults' => ["{$type->name} sleeps up to {$type->max_adults} adults and {$type->max_children} children."]]);
            }
            if (($full = HotelAvailability::fullNight($type, $in, $out)) !== null) {
                throw ValidationException::withMessages(['check_in' => ["{$type->name} is fully booked on the night of ".Carbon::parse($full)->format('D j M').'. Try other dates or another room.']]);
            }

            $phone = Phone::normalise($v['phone']) ?? $v['phone'];
            $customer = Customer::firstOrCreate(['phone' => $phone], ['name' => $v['name']]);
            $price = HotelPricing::quote($type, $in, $out);

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
                'total' => $price['total'],
                'status' => RoomBooking::PENDING,
                'source' => 'website',
                'notes' => $v['notes'] ?? null,
            ]);

            PublicOrderController::reportConversion(Conversion::forRoomRequest($booking, $request));
            EmailRoomGuest::notify($booking, 'received');

            return response()->json([
                'request_id' => $booking->id,
                'room_type' => $type->name,
                'nights' => $price['nights'],
                'total' => $price['total'],
            ], 201);
        });
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function stay(array $v): array
    {
        $tz = config('app.timezone');
        $in = Carbon::createFromFormat('Y-m-d', $v['check_in'], $tz)->startOfDay();
        $out = Carbon::createFromFormat('Y-m-d', $v['check_out'], $tz)->startOfDay();
        $today = now($tz)->startOfDay();

        if ($in->lt($today) || $in->gt($today->copy()->addYear())) {
            throw ValidationException::withMessages(['check_in' => ['Choose a check-in date from today up to a year ahead.']]);
        }
        if ($in->diffInDays($out) > self::MAX_NIGHTS) {
            throw ValidationException::withMessages(['check_out' => ['Book up to '.self::MAX_NIGHTS.' nights online; call us for longer stays.']]);
        }

        return [$in, $out];
    }

    private function hotel(string $code): Tenant
    {
        $tenant = Tenant::where('slug', $code)->first();

        if ($tenant === null || ! $tenant->hasModule(Modules::HOTEL)) {
            abort(404, 'This restaurant does not take room bookings online.');
        }

        return $tenant;
    }
}
