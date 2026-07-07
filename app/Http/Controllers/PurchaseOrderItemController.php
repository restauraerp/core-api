<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;

class PurchaseOrderItemController extends Controller
{
    public function index()
    {
        return response()->json(PurchaseOrderItem::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            
        ]);

        $purchaseOrderItem = PurchaseOrderItem::create($validated);
        return response()->json($purchaseOrderItem, 201);
    }

    public function show(PurchaseOrderItem $purchaseOrderItem)
    {
        return response()->json($purchaseOrderItem);
    }

    public function update(Request $request, PurchaseOrderItem $purchaseOrderItem)
    {
        $validated = $request->validate([
            
        ]);

        $purchaseOrderItem->update($validated);
        return response()->json($purchaseOrderItem);
    }

    public function destroy(PurchaseOrderItem $purchaseOrderItem)
    {
        $purchaseOrderItem->delete();
        return response()->json(null, 204);
    }
}