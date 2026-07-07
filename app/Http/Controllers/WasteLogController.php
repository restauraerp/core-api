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