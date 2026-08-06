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
            'points_per_amount' => 'nullable|numeric|min:0',
            'cash_per_point' => 'nullable|numeric|min:0',
            'tier_thresholds' => 'nullable|array',
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
            'points_per_amount' => 'nullable|numeric|min:0',
            'cash_per_point' => 'nullable|numeric|min:0',
            'tier_thresholds' => 'nullable|array',
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
