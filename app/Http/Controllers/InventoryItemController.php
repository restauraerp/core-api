<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use Illuminate\Http\Request;

class InventoryItemController extends Controller
{
    public function index(Request $request)
    {
        $query = InventoryItem::with(['locations']);
        
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%")->orWhere('title', 'like', "%{$search}%");
        }
        
        if ($request->has('nopaginate')) {
            return response()->json($query->get());
        }
        return response()->json($query->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string',
            'name' => 'nullable|string',
            'sku' => 'nullable|string',
            'unit' => 'nullable|string',
            'min_stock_level' => 'nullable|string',
            'current_stock' => 'nullable|string',
            'cost_per_unit' => 'nullable|string',
            'image' => 'nullable|image|max:5120',
            'locations' => 'nullable|array',
            'locations.*.location_id' => 'required_with:locations|integer',
            'locations.*.quantity' => 'nullable|numeric',
            'locations.*.is_active' => 'required_with:locations|boolean'
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('inventory', 'public');
            $validated['image'] = $path;
        }

        $inventoryItem = InventoryItem::create($validated);

        if (isset($validated['locations'])) {
            $syncData = [];
            foreach ($validated['locations'] as $loc) {
                $syncData[$loc['location_id']] = [
                    'quantity' => $loc['quantity'] ?? 0,
                    'is_active' => $loc['is_active']
                ];
            }
            $inventoryItem->locations()->sync($syncData);
        }

        return response()->json($inventoryItem->load('locations'), 201);
    }

    public function show(InventoryItem $inventoryItem)
    {
        return response()->json($inventoryItem->load('locations'));
    }

    public function update(Request $request, InventoryItem $inventoryItem)
    {
        $validated = $request->validate([
            'title' => 'nullable|string',
            'name' => 'nullable|string',
            'sku' => 'nullable|string',
            'unit' => 'nullable|string',
            'min_stock_level' => 'nullable|string',
            'current_stock' => 'nullable|string',
            'cost_per_unit' => 'nullable|string',
            'image' => 'nullable|image|max:5120',
            'locations' => 'nullable|array',
            'locations.*.location_id' => 'required_with:locations|integer',
            'locations.*.quantity' => 'nullable|numeric',
            'locations.*.is_active' => 'required_with:locations|boolean'
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('inventory', 'public');
            $validated['image'] = $path;
        }

        $inventoryItem->update($validated);

        if (isset($validated['locations'])) {
            $syncData = [];
            foreach ($validated['locations'] as $loc) {
                $syncData[$loc['location_id']] = [
                    'quantity' => $loc['quantity'] ?? 0,
                    'is_active' => $loc['is_active']
                ];
            }
            $inventoryItem->locations()->sync($syncData);
        }

        return response()->json($inventoryItem->load('locations'));
    }

    public function destroy(InventoryItem $inventoryItem)
    {
        $inventoryItem->delete();
        return response()->json(null, 204);
    }
}