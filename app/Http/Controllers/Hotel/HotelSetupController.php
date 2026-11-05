<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomBooking;
use App\Models\RoomType;
use App\Support\Assets\Media;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The hotel's rooms: room types (what guests book) and the numbered rooms of
 * each. Behind module:hotel and manage_hotel.
 */
class HotelSetupController extends Controller
{
    /** GET /hotel/room-types - every type with its rooms. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json(['room_types' => RoomType::with(['location:id,name', 'rooms'])
            ->orderBy('sort_order')->orderBy('rate')->get()
            ->map(fn (RoomType $t) => $this->present($t))]);
    }

    public function storeType(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $type = RoomType::create($this->typeRules($request, true));

        return response()->json($this->present($type->load(['location:id,name', 'rooms'])), 201);
    }

    public function updateType(Request $request, RoomType $roomType): JsonResponse
    {
        $this->authorizeManage($request);

        $roomType->update($this->typeRules($request, false));

        return response()->json($this->present($roomType->fresh(['location:id,name', 'rooms'])));
    }

    public function destroyType(Request $request, RoomType $roomType): JsonResponse
    {
        $this->authorizeManage($request);

        if ($this->hasStaysToCome(RoomBooking::where('room_type_id', $roomType->id))) {
            throw ValidationException::withMessages(['room_type' => ['This room type has stays to come. Cancel or move them first - or switch it off instead.']]);
        }

        $roomType->delete();

        return response()->json(null, 204);
    }

    /** POST /hotel/room-types/{type}/rooms - one room, or several: "101-110" or "101, 102, 105". */
    public function storeRooms(Request $request, RoomType $roomType): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate([
            'numbers' => ['required', 'string', 'max:300'],
            'floor' => ['nullable', 'string', 'max:20'],
        ]);

        $numbers = self::expandNumbers($v['numbers']);
        if ($numbers === [] || count($numbers) > 200) {
            throw ValidationException::withMessages(['numbers' => ['Give room numbers like 101, or 101-110 (up to 200 at a time).']]);
        }

        $existing = Room::where('room_type_id', $roomType->id)->whereIn('number', $numbers)->pluck('number')->all();
        foreach (array_diff($numbers, $existing) as $number) {
            Room::create(['room_type_id' => $roomType->id, 'number' => $number, 'floor' => $v['floor'] ?? null]);
        }

        return response()->json($this->present($roomType->fresh(['location:id,name', 'rooms'])), 201);
    }

    public function updateRoom(Request $request, Room $room): JsonResponse
    {
        $this->authorizeManage($request);

        $v = $request->validate([
            'number' => ['sometimes', 'string', 'max:20', Rule::unique('rooms', 'number')->where('room_type_id', $room->room_type_id)->where('tenant_id', $room->tenant_id)->ignore($room->id)],
            'floor' => ['nullable', 'string', 'max:20'],
            'in_service' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        $room->update($v);

        return response()->json($this->present($room->type()->with(['location:id,name', 'rooms'])->first()));
    }

    public function destroyRoom(Request $request, Room $room): JsonResponse
    {
        $this->authorizeManage($request);

        if ($this->hasStaysToCome(RoomBooking::where('room_id', $room->id))) {
            throw ValidationException::withMessages(['room' => ['This room has guests booked into it. Move them to another room first - or take it out of service.']]);
        }

        $type = $room->type;
        $room->delete();

        return response()->json($this->present($type->fresh(['location:id,name', 'rooms'])));
    }

    /** POST /hotel/photos - a room photo; the path goes into a room type's photos. */
    public function uploadPhoto(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);

        return response()->json(['path' => $request->file('image')->store('hotel/'.app(TenantContext::class)->id(), Media::disk())], 201);
    }

    /** "101-105, 110" -> ["101", "102", "103", "104", "105", "110"] */
    public static function expandNumbers(string $input): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($input)) as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d{1,5})-(\d{1,5})$/', $part, $m) && (int) $m[2] >= (int) $m[1] && (int) $m[2] - (int) $m[1] < 200) {
                foreach (range((int) $m[1], (int) $m[2]) as $n) {
                    $out[] = (string) $n;
                }
            } elseif (preg_match('/^[A-Za-z0-9-]{1,20}$/', $part)) {
                $out[] = $part;
            } else {
                return [];
            }
        }

        return array_values(array_unique($out));
    }

    private function typeRules(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'location_id' => [$required, 'integer', $this->tenantExists('locations')],
            'name' => [$required, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:3000'],
            'max_adults' => [$required, 'integer', 'min:1', 'max:20'],
            'max_children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'rate' => [$required, 'numeric', 'min:0', 'max:9999999'],
            'weekend_rate' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'beds' => ['nullable', 'string', 'max:120'],
            'size_sqft' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'amenities' => ['nullable', 'array', 'max:30'],
            'amenities.*' => ['string', 'max:60'],
            'photos' => ['nullable', 'array', 'max:12'],
            // Only photos uploaded here, so nothing else can be shown as one.
            'photos.*' => ['string', 'regex:#^hotel/[0-9]+/[A-Za-z0-9._-]+$#'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    private function hasStaysToCome($query): bool
    {
        return $query->where('check_out', '>', now(config('app.timezone'))->format('Y-m-d'))
            ->whereIn('status', [RoomBooking::PENDING, ...RoomBooking::HOLDING])
            ->exists();
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can('manage_hotel')) {
            abort(403, 'You do not have permission to manage the hotel.');
        }
    }

    /** @return array<string, mixed> */
    public static function presentType(RoomType $t, bool $withRooms = true): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'location' => $t->location ? ['id' => $t->location->id, 'name' => $t->location->name] : null,
            'max_adults' => $t->max_adults,
            'max_children' => $t->max_children,
            'rate' => (float) $t->rate,
            'weekend_rate' => $t->weekend_rate !== null ? (float) $t->weekend_rate : null,
            'beds' => $t->beds,
            'size_sqft' => $t->size_sqft,
            'amenities' => $t->amenities ?? [],
            'photos' => $t->photos ?? [],
            'is_active' => $t->is_active,
        ] + ($withRooms ? ['rooms' => $t->rooms->map(fn (Room $r) => [
            'id' => $r->id, 'number' => $r->number, 'floor' => $r->floor, 'in_service' => $r->in_service, 'notes' => $r->notes,
        ])->values()] : []);
    }

    private function present(RoomType $t): array
    {
        return self::presentType($t);
    }
}
