<?php

namespace App\Http\Controllers;

use App\Models\LoyaltySetting;
use Illuminate\Http\Request;

class LoyaltySettingController extends Controller
{
    public function index()
    {
        return response()->json(LoyaltySetting::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            
        ]);

        $loyaltySetting = LoyaltySetting::create($validated);
        return response()->json($loyaltySetting, 201);
    }

    public function show(LoyaltySetting $loyaltySetting)
    {
        return response()->json($loyaltySetting);
    }

    public function update(Request $request, LoyaltySetting $loyaltySetting)
    {
        $validated = $request->validate([
            
        ]);

        $loyaltySetting->update($validated);
        return response()->json($loyaltySetting);
    }

    public function destroy(LoyaltySetting $loyaltySetting)
    {
        $loyaltySetting->delete();
        return response()->json(null, 204);
    }
}