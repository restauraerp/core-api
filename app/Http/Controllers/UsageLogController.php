<?php

namespace App\Http\Controllers;

use App\Models\UsageLog;
use Illuminate\Http\Request;

class UsageLogController extends Controller
{
    public function index()
    {
        return response()->json(UsageLog::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            
        ]);

        $usageLog = UsageLog::create($validated);
        return response()->json($usageLog, 201);
    }

    public function show(UsageLog $usageLog)
    {
        return response()->json($usageLog);
    }

    public function update(Request $request, UsageLog $usageLog)
    {
        $validated = $request->validate([
            
        ]);

        $usageLog->update($validated);
        return response()->json($usageLog);
    }

    public function destroy(UsageLog $usageLog)
    {
        $usageLog->delete();
        return response()->json(null, 204);
    }
}