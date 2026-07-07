<?php

namespace App\Http\Controllers;

use App\Models\Hall;
use Illuminate\Http\Request;

class HallController extends Controller
{
    public function index($locationId)
    {
        return response()->json(Hall::where('location_id', $locationId)->get());
    }

    public function store(Request $request, $locationId)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'capacity' => 'nullable|integer|min:1',
            'price' => 'nullable|numeric|min:0',
        ]);

        $validated['location_id'] = $locationId;
        
        $hall = Hall::create($validated);
        return response()->json($hall, 201);
    }

    public function show(Hall $hall)
    {
        return response()->json($hall);
    }

    public function update(Request $request, Hall $hall)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'capacity' => 'nullable|integer|min:1',
            'price' => 'nullable|numeric|min:0',
        ]);

        $hall->update($validated);
        return response()->json($hall);
    }

    public function destroy(Hall $hall)
    {
        $hall->delete();
        return response()->json(null, 204);
    }
}
