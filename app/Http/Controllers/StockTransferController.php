<?php

namespace App\Http\Controllers;

use App\Models\StockTransfer;
use Illuminate\Http\Request;

class StockTransferController extends Controller
{
    public function index()
    {
        return response()->json(StockTransfer::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            
        ]);

        $stockTransfer = StockTransfer::create($validated);
        return response()->json($stockTransfer, 201);
    }

    public function show(StockTransfer $stockTransfer)
    {
        return response()->json($stockTransfer);
    }

    public function update(Request $request, StockTransfer $stockTransfer)
    {
        $validated = $request->validate([
            
        ]);

        $stockTransfer->update($validated);
        return response()->json($stockTransfer);
    }

    public function destroy(StockTransfer $stockTransfer)
    {
        $stockTransfer->delete();
        return response()->json(null, 204);
    }
}