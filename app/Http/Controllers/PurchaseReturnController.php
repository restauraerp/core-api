<?php

namespace App\Http\Controllers;

use App\Models\PurchaseReturn;
use Illuminate\Http\Request;

class PurchaseReturnController extends Controller
{
    public function index()
    {
        return response()->json(PurchaseReturn::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'purchase_order_id' => ['required', 'integer', $this->tenantExists('purchase_orders')],
            'reason' => 'nullable|string',
            'total_refund' => 'nullable|numeric|min:0',
        ]);

        $purchaseReturn = PurchaseReturn::create($validated);

        return response()->json($purchaseReturn, 201);
    }

    public function show(PurchaseReturn $purchaseReturn)
    {
        return response()->json($purchaseReturn);
    }

    public function update(Request $request, PurchaseReturn $purchaseReturn)
    {
        $validated = $request->validate([
            'purchase_order_id' => ['sometimes', 'required', 'integer', $this->tenantExists('purchase_orders')],
            'reason' => 'nullable|string',
            'total_refund' => 'nullable|numeric|min:0',
        ]);

        $purchaseReturn->update($validated);

        return response()->json($purchaseReturn);
    }

    public function destroy(PurchaseReturn $purchaseReturn)
    {
        $purchaseReturn->delete();

        return response()->json(null, 204);
    }
}
