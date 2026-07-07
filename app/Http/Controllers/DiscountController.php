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