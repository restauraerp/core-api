<?php

namespace App\Http\Controllers\Venues;

use App\Http\Controllers\Controller;
use App\Models\VenuePackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Per-person food packages for venue bookings. Behind module:crm and manage_venue_bookings. */
class VenuePackageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json(['packages' => VenuePackage::orderBy('sort_order')->orderBy('price_per_person')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json(VenuePackage::create($this->validated($request, true)), 201);
    }

    public function update(Request $request, VenuePackage $package): JsonResponse
    {
        $this->authorizeManage($request);

        $package->update($this->validated($request, false));

        return response()->json($package->fresh());
    }

    /** Bookings keep their own copy of the package's name and price, so deleting one changes no booking. */
    public function destroy(Request $request, VenuePackage $package): JsonResponse
    {
        $this->authorizeManage($request);

        $package->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_per_person' => [$required, 'numeric', 'min:0', 'max:999999'],
            'min_guests' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can('manage_venue_bookings')) {
            abort(403, 'You do not have permission to manage venue bookings.');
        }
    }
}
