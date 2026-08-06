<?php

namespace App\Http\Controllers;

use App\Models\OrderItem;
use Illuminate\Http\Request;

class OrderItemController extends Controller
{
    public function index()
    {
        return response()->json(OrderItem::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', $this->tenantExists('orders')],
            'product_id' => ['required', 'integer', $this->tenantExists('products')],
            'quantity' => 'nullable|integer|min:1',
            'price' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $orderItem = OrderItem::create($validated);

        return response()->json($orderItem, 201);
    }

    public function show(OrderItem $orderItem)
    {
        return response()->json($orderItem);
    }

    public function update(Request $request, OrderItem $orderItem)
    {
        $validated = $request->validate([
            'order_id' => ['sometimes', 'required', 'integer', $this->tenantExists('orders')],
            'product_id' => ['sometimes', 'required', 'integer', $this->tenantExists('products')],
            'quantity' => 'nullable|integer|min:1',
            'price' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $orderItem->update($validated);

        return response()->json($orderItem);
    }

    public function destroy(OrderItem $orderItem)
    {
        $orderItem->delete();

        return response()->json(null, 204);
    }
}
