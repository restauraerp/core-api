<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    public function index()
    {
        return response()->json(Discount::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'nullable|string|max:255',
            'discount_type' => 'nullable|string|max:255',
            'value' => 'nullable|numeric|min:0',
            'valid_until' => 'nullable|date',
            'is_active' => 'boolean',
        ]);

        $discount = Discount::create($validated);

        return response()->json($discount, 201);
    }

    public function show(Discount $discount)
    {
        return response()->json($discount);
    }

    public function update(Request $request, Discount $discount)
    {
        $validated = $request->validate([
            'code' => 'nullable|string|max:255',
            'discount_type' => 'nullable|string|max:255',
            'value' => 'nullable|numeric|min:0',
            'valid_until' => 'nullable|date',
            'is_active' => 'boolean',
        ]);

        $discount->update($validated);

        return response()->json($discount);
    }

    public function destroy(Discount $discount)
    {
        $discount->delete();

        return response()->json(null, 204);
    }
}
