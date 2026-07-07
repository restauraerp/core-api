<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function index()
    {
        return response()->json(Location::with(['halls', 'tables', 'cctvCameras'])->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|in:head_office,branch',
            'address' => 'nullable|string',
            'map_url' => 'nullable|url',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
        ]);

        $location = Location::create($validated);
        return response()->json($location, 201);
    }

    public function show(Location $location)
    {
        return response()->json($location->load(['halls', 'tables', 'cctvCameras']));
    }

    public function update(Request $request, Location $location)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'nullable|string|in:head_office,branch',
            'address' => 'nullable|string',
            'map_url' => 'nullable|url',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
        ]);

        $location->update($validated);
        return response()->json($location);
    }

    public function destroy(Location $location)
    {
        $location->delete();
        return response()->json(null, 204);
    }
}
