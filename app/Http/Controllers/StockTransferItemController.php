<?php

namespace App\Http\Controllers;

use App\Models\StockTransferItem;
use Illuminate\Http\Request;

class StockTransferItemController extends Controller
{
    public function index()
    {
        return response()->json(StockTransferItem::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            
        ]);

        $stockTransferItem = StockTransferItem::create($validated);
        return response()->json($stockTransferItem, 201);
    }

    public function show(StockTransferItem $stockTransferItem)
    {
        return response()->json($stockTransferItem);
    }

    public function update(Request $request, StockTransferItem $stockTransferItem)
    {
        $validated = $request->validate([
            
        ]);

        $stockTransferItem->update($validated);
        return response()->json($stockTransferItem);
    }

    public function destroy(StockTransferItem $stockTransferItem)
    {
        $stockTransferItem->delete();
        return response()->json(null, 204);
    }
}