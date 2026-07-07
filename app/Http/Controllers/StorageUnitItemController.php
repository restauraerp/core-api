<?php

namespace App\Http\Controllers;

use App\Models\StorageUnitItem;
use Illuminate\Http\Request;

class StorageUnitItemController extends Controller
{
    public function index()
    {
        return response()->json(StorageUnitItem::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'storage_unit_id' => 'nullable|integer',
            'inventory_item_id' => 'nullable|integer',
            'quantity' => 'nullable|string',
        ]);

        $storageUnitItem = StorageUnitItem::create($validated);
        return response()->json($storageUnitItem, 201);
    }

    public function show(StorageUnitItem $storageUnitItem)
    {
        return response()->json($storageUnitItem);
    }

    public function update(Request $request, StorageUnitItem $storageUnitItem)
    {
        $validated = $request->validate([
            'storage_unit_id' => 'nullable|integer',
            'inventory_item_id' => 'nullable|integer',
            'quantity' => 'nullable|string',
        ]);

        $storageUnitItem->update($validated);
        return response()->json($storageUnitItem);
    }

    public function destroy(StorageUnitItem $storageUnitItem)
    {
        $storageUnitItem->delete();
        return response()->json(null, 204);
    }
}