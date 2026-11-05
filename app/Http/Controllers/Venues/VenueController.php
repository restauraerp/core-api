<?php

namespace App\Http\Controllers\Venues;

use App\Http\Controllers\Controller;
use App\Models\Hall;
use App\Models\VenueBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The restaurant's venues - its halls, as things to hire out: rent, guest
 * range, cleanup time, description. Behind module:crm and
 * manage_venue_bookings, so whoever runs events can set them up without the
 * rights to edit the branch itself.
 */
class VenueController extends Controller
{
    /** GET /venues?location_id= */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json(['venues' => Hall::with('location:id,name')
            ->when($request->integer('location_id'), fn ($q, $id) => $q->where('location_id', $id))
            ->orderBy('location_id')->orderBy('name')
            ->get()
            ->map(fn (Hall $h) => $this->present($h))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $hall = Hall::create($this->validated($request, creating: true));

        return response()->json($this->present($hall->load('location:id,name')), 201);
    }

    public function update(Request $request, Hall $venue): JsonResponse
    {
        $this->authorizeManage($request);

        $venue->update($this->validated($request, creating: false));

        return response()->json($this->present($venue->fresh('location:id,name')));
    }

    public function destroy(Request $request, Hall $venue): JsonResponse
    {
        $this->authorizeManage($request);

        if (VenueBooking::where('hall_id', $venue->id)->where('ends_at', '>', now())->whereIn('status', [VenueBooking::PENDING, VenueBooking::CONFIRMED])->exists()) {
            abort(422, 'This venue has bookings to come. Cancel or move them first - or switch the venue off instead.');
        }

        $venue->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $validated = $request->validate([
            'location_id' => [$required, 'integer', $this->tenantExists('locations')],
            'name' => [$required, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'capacity' => [$required, 'integer', 'min:1', 'max:5000'],
            'min_guests' => ['nullable', 'integer', 'min:1', 'lte:capacity'],
            'price' => [$required, 'numeric', 'min:0', 'max:99999999'],
            'rent_type' => [$required, Rule::in([Hall::PER_EVENT, Hall::PER_HOUR])],
            'buffer_minutes' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $validated;
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can('manage_venue_bookings')) {
            abort(403, 'You do not have permission to manage venue bookings.');
        }
    }

    /** @return array<string, mixed> */
    private function present(Hall $h): array
    {
        return [
            'id' => $h->id,
            'name' => $h->name,
            'description' => $h->description,
            'location' => $h->location ? ['id' => $h->location->id, 'name' => $h->location->name] : null,
            'capacity' => $h->capacity,
            'min_guests' => $h->min_guests,
            'price' => (float) $h->price,
            'rent_type' => $h->rent_type ?? Hall::PER_EVENT,
            'buffer_minutes' => $h->buffer_minutes ?? 60,
            'is_active' => $h->is_active ?? true,
        ];
    }
}
