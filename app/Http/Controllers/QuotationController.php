<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use Illuminate\Http\Request;

class QuotationController extends Controller
{
    public function index()
    {
        return response()->json(Quotation::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            
        ]);

        $quotation = Quotation::create($validated);
        return response()->json($quotation, 201);
    }

    public function show(Quotation $quotation)
    {
        return response()->json($quotation);
    }

    public function update(Request $request, Quotation $quotation)
    {
        $validated = $request->validate([
            
        ]);

        $quotation->update($validated);
        return response()->json($quotation);
    }

    public function destroy(Quotation $quotation)
    {
        $quotation->delete();
        return response()->json(null, 204);
    }
}