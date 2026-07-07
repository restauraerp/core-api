<?php

namespace App\Http\Controllers;

use App\Models\StorageUnit;
use Illuminate\Http\Request;

class StorageUnitController extends Controller
{
    public function index()
    {
        return response()->json(StorageUnit::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'location_id' => 'nullable|integer',
            'name' => 'nullable|string',
            'type' => 'nullable|string',
        ]);

        $storageUnit = StorageUnit::create($validated);
        return response()->json($storageUnit, 201);
    }

    public function show(StorageUnit $storageUnit)
    {
        return response()->json($storageUnit);
    }

    public function update(Request $request, StorageUnit $storageUnit)
    {
        $validated = $request->validate([
            'location_id' => 'nullable|integer',
            'name' => 'nullable|string',
            'type' => 'nullable|string',
        ]);

        $storageUnit->update($validated);
        return response()->json($storageUnit);
    }

    public function destroy(StorageUnit $storageUnit)
    {
        $storageUnit->delete();
        return response()->json(null, 204);
    }
}