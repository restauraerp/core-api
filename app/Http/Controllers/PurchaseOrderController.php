<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function index()
    {
        return response()->json(PurchaseOrder::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            
        ]);

        $purchaseOrder = PurchaseOrder::create($validated);
        return response()->json($purchaseOrder, 201);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        return response()->json($purchaseOrder);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $validated = $request->validate([
            
        ]);

        $purchaseOrder->update($validated);
        return response()->json($purchaseOrder);
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->delete();
        return response()->json(null, 204);
    }
}