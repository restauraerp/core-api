<?php

namespace App\Http\Controllers;

use App\Models\WasteLog;
use Illuminate\Http\Request;

class WasteLogController extends Controller
{
    public function index()
    {
        return response()->json(WasteLog::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'inventory_item_id' => ['required', 'integer', $this->tenantExists('inventory_items')],
            'location_id' => ['required', 'integer', $this->tenantExists('locations')],
            'quantity' => 'nullable|numeric|min:0',
            'reason' => 'nullable|string',
            'logged_by' => ['nullable', 'integer', $this->tenantExists('users')],
        ]);

        $wasteLog = WasteLog::create($validated);

        return response()->json($wasteLog, 201);
    }

    public function show(WasteLog $wasteLog)
    {
        return response()->json($wasteLog);
    }

    public function update(Request $request, WasteLog $wasteLog)
    {
        $validated = $request->validate([
            'inventory_item_id' => ['sometimes', 'required', 'integer', $this->tenantExists('inventory_items')],
            'location_id' => ['sometimes', 'required', 'integer', $this->tenantExists('locations')],
            'quantity' => 'nullable|numeric|min:0',
            'reason' => 'nullable|string',
            'logged_by' => ['nullable', 'integer', $this->tenantExists('users')],
        ]);

        $wasteLog->update($validated);

        return response()->json($wasteLog);
    }

    public function destroy(WasteLog $wasteLog)
    {
        $wasteLog->delete();

        return response()->json(null, 204);
    }
}
