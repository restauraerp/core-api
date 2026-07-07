<?php

namespace App\Http\Controllers;

use App\Models\QuotationItem;
use Illuminate\Http\Request;

class QuotationItemController extends Controller
{
    public function index()
    {
        return response()->json(QuotationItem::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            
        ]);

        $quotationItem = QuotationItem::create($validated);
        return response()->json($quotationItem, 201);
    }

    public function show(QuotationItem $quotationItem)
    {
        return response()->json($quotationItem);
    }

    public function update(Request $request, QuotationItem $quotationItem)
    {
        $validated = $request->validate([
            
        ]);

        $quotationItem->update($validated);
        return response()->json($quotationItem);
    }

    public function destroy(QuotationItem $quotationItem)
    {
        $quotationItem->delete();
        return response()->json(null, 204);
    }
}