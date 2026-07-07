<?php

namespace App\Http\Controllers;

use App\Models\CctvCamera;
use Illuminate\Http\Request;

class CctvCameraController extends Controller
{
    public function index($locationId)
    {
        return response()->json(CctvCamera::where('location_id', $locationId)->get());
    }

    public function store(Request $request, $locationId)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'stream_url' => 'nullable|url',
        ]);

        $validated['location_id'] = $locationId;
        
        $cctvCamera = CctvCamera::create($validated);
        return response()->json($cctvCamera, 201);
    }

    public function show(CctvCamera $cctvCamera)
    {
        return response()->json($cctvCamera);
    }

    public function update(Request $request, CctvCamera $cctvCamera)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'stream_url' => 'nullable|url',
        ]);

        $cctvCamera->update($validated);
        return response()->json($cctvCamera);
    }

    public function destroy(CctvCamera $cctvCamera)
    {
        $cctvCamera->delete();
        return response()->json(null, 204);
    }
}
