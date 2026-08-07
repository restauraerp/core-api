<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Support\Inventory\SellableInventory;
use Illuminate\Http\Request;

/**
 * The inventory catalogue: what a restaurant stocks, in what unit, at what
 * minimum level, and which outlets carry it.
 *
 * Two things this deliberately does not accept, because neither is anybody's
 * opinion - both are facts recorded elsewhere:
 *
 *   - `current_stock` and per-outlet quantities come from purchase orders,
 *     which is what puts goods in a restaurant.
 *   - `cost_per_unit` is whatever the last delivery charged, maintained by
 *     PurchaseOrderStock.
 *
 * Both are ignored rather than rejected, so an older client keeps working - it
 * just cannot move either number.
 */
class InventoryItemController extends Controller
{
    public function __construct(private readonly SellableInventory $sellable) {}

    public function index(Request $request)
    {
        $query = InventoryItem::with(['locations', 'product']);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('title', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%");
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
            'description' => 'nullable|string',
            'sku' => 'nullable|string',
            'unit' => 'nullable|string',
            'min_stock_level' => 'nullable|string',
            'image' => 'nullable|image|max:5120',
            'is_sellable' => 'nullable|boolean',
            // A till cannot sell something with no price on it.
            'selling_price' => 'nullable|numeric|min:0|required_if_accepted:is_sellable',
            'locations' => 'nullable|array',
            'locations.*.location_id' => 'required_with:locations|integer',
            'locations.*.is_active' => 'required_with:locations|boolean',
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('inventory', 'public');
            $validated['image'] = $path;
        }

        $inventoryItem = InventoryItem::create($validated);

        $this->syncLocations($inventoryItem, $validated['locations'] ?? null);
        $this->sellable->sync($inventoryItem);

        return response()->json($inventoryItem->load(['locations', 'product']), 201);
    }

    public function show(InventoryItem $inventoryItem)
    {
        return response()->json($inventoryItem->load(['locations', 'product']));
    }

    public function update(Request $request, InventoryItem $inventoryItem)
    {
        $validated = $request->validate([
            'title' => 'nullable|string',
            'description' => 'nullable|string',
            'sku' => 'nullable|string',
            'unit' => 'nullable|string',
            'min_stock_level' => 'nullable|string',
            'image' => 'nullable|image|max:5120',
            'is_sellable' => 'nullable|boolean',
            // A till cannot sell something with no price on it.
            'selling_price' => 'nullable|numeric|min:0|required_if_accepted:is_sellable',
            'locations' => 'nullable|array',
            'locations.*.location_id' => 'required_with:locations|integer',
            'locations.*.is_active' => 'required_with:locations|boolean',
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('inventory', 'public');
            $validated['image'] = $path;
        }

        $inventoryItem->update($validated);

        $this->syncLocations($inventoryItem, $validated['locations'] ?? null);
        $this->sellable->sync($inventoryItem);

        return response()->json($inventoryItem->load(['locations', 'product']));
    }

    public function destroy(InventoryItem $inventoryItem)
    {
        $inventoryItem->delete();

        return response()->json(null, 204);
    }

    /**
     * Say which outlets carry this item - not how much of it they hold.
     *
     * Quantities are never taken from this request. Stock arrives through a
     * purchase order and leaves through the sales it is cooked into, so a
     * typed-in level would be a number with no document behind it: nobody could
     * say later where it came from, and the next delivery would silently
     * disagree with it. Existing quantities are carried through untouched, and
     * an outlet added here starts at zero until something is delivered to it.
     *
     * @param  array<int, array{location_id: int, is_active: bool}>|null  $locations
     */
    private function syncLocations(InventoryItem $item, ?array $locations): void
    {
        if ($locations === null) {
            return;
        }

        $held = $item->locations()->pluck('inventory_item_location.quantity', 'locations.id');

        $syncData = [];

        foreach ($locations as $location) {
            $syncData[$location['location_id']] = [
                'quantity' => $held[$location['location_id']] ?? 0,
                'is_active' => $location['is_active'],
            ];
        }

        $item->locations()->sync($syncData);

        // The headline figure follows the outlets, exactly as it does when a
        // delivery lands (see PurchaseOrderStock).
        $item->forceFill([
            'current_stock' => $item->locations()->sum('inventory_item_location.quantity'),
        ])->save();
    }
}
