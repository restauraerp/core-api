<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function index()
    {
        return response()->json(Reservation::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', $this->tenantExists('customers')],
            'location_id' => ['required', 'integer', $this->tenantExists('locations')],
            'hall_id' => ['nullable', 'integer', $this->tenantExists('halls')],
            'table_id' => ['nullable', 'integer', $this->tenantExists('tables')],
            'reservation_date' => 'nullable|date',
            'guest_count' => 'nullable|integer|min:1',
            'status' => 'nullable|string|max:255',
        ]);

        $reservation = Reservation::create($validated);

        return response()->json($reservation, 201);
    }

    public function show(Reservation $reservation)
    {
        return response()->json($reservation);
    }

    public function update(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'customer_id' => ['sometimes', 'required', 'integer', $this->tenantExists('customers')],
            'location_id' => ['sometimes', 'required', 'integer', $this->tenantExists('locations')],
            'hall_id' => ['nullable', 'integer', $this->tenantExists('halls')],
            'table_id' => ['nullable', 'integer', $this->tenantExists('tables')],
            'reservation_date' => 'nullable|date',
            'guest_count' => 'nullable|integer|min:1',
            'status' => 'nullable|string|max:255',
        ]);

        $reservation->update($validated);

        return response()->json($reservation);
    }

    public function destroy(Reservation $reservation)
    {
        $reservation->delete();

        return response()->json(null, 204);
    }
}
