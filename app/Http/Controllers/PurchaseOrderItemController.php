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
            'purchase_order_id' => ['required', 'integer', $this->tenantExists('purchase_orders')],
            'inventory_item_id' => ['required', 'integer', $this->tenantExists('inventory_items')],
            'quantity' => 'nullable|numeric|min:0',
            'price' => 'nullable|numeric|min:0',
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
            'purchase_order_id' => ['sometimes', 'required', 'integer', $this->tenantExists('purchase_orders')],
            'inventory_item_id' => ['sometimes', 'required', 'integer', $this->tenantExists('inventory_items')],
            'quantity' => 'nullable|numeric|min:0',
            'price' => 'nullable|numeric|min:0',
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
