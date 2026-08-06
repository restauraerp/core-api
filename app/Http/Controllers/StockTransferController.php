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
            'inventory_item_id' => ['required', 'integer', $this->tenantExists('inventory_items')],
            'from_storage_id' => ['required', 'integer', $this->tenantExists('storage_units')],
            'to_storage_id' => ['required', 'integer', $this->tenantExists('storage_units')],
            'quantity' => 'nullable|numeric|min:0',
            'transferred_by' => ['nullable', 'integer', $this->tenantExists('users')],
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
            'inventory_item_id' => ['sometimes', 'required', 'integer', $this->tenantExists('inventory_items')],
            'from_storage_id' => ['sometimes', 'required', 'integer', $this->tenantExists('storage_units')],
            'to_storage_id' => ['sometimes', 'required', 'integer', $this->tenantExists('storage_units')],
            'quantity' => 'nullable|numeric|min:0',
            'transferred_by' => ['nullable', 'integer', $this->tenantExists('users')],
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
