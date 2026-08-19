<?php

namespace App\Http\Controllers;

use App\Models\Image;
use App\Models\InventoryItem;
use App\Support\Inventory\SellableInventory;
use Illuminate\Http\Request;

class InventoryItemController extends Controller
{
    public function __construct(private readonly SellableInventory $sellable) {}

    public function index(Request $request)
    {
        $query = InventoryItem::with(['locations', 'product', 'images']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_sellable')) {
            $query->where('is_sellable', (bool) $request->input('is_sellable'));
        }

        if ($request->has('nopaginate')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate(config('pagination.limit')));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'                    => 'nullable|string',
            'description'              => 'nullable|string',
            'sku'                      => 'nullable|string',
            'unit'                     => 'nullable|string',
            'min_stock_level'          => 'nullable|string',
            'is_sellable'              => 'nullable|boolean',
            'selling_price'            => 'nullable|numeric|min:0|required_if_accepted:is_sellable',
            'locations'                => 'nullable|array',
            'locations.*.location_id'  => 'required_with:locations|integer',
            'locations.*.is_active'    => 'required_with:locations|boolean',
            'images'                   => 'nullable|array',
            'images.*'                 => 'image|max:5120',
            'featured_image_index'     => 'nullable|integer',
        ]);

        $inventoryItem = InventoryItem::create($validated);

        $this->syncLocations($inventoryItem, $validated['locations'] ?? null);
        $this->sellable->sync($inventoryItem);

        $featuredIndex = $request->input('featured_image_index');
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $i => $file) {
                $path = $file->store('inventory', 'public');
                Image::create([
                    'imageable_id'   => $inventoryItem->id,
                    'imageable_type' => InventoryItem::class,
                    'type'           => 'image',
                    'url'            => $path,
                    'is_featured'    => ($featuredIndex !== null && (int) $featuredIndex === $i),
                ]);
            }
        }

        return response()->json($inventoryItem->load(['locations', 'product', 'images']), 201);
    }

    public function show(InventoryItem $inventoryItem)
    {
        return response()->json($inventoryItem->load(['locations', 'product', 'images']));
    }

    public function update(Request $request, InventoryItem $inventoryItem)
    {
        $validated = $request->validate([
            'title'                    => 'nullable|string',
            'description'              => 'nullable|string',
            'sku'                      => 'nullable|string',
            'unit'                     => 'nullable|string',
            'min_stock_level'          => 'nullable|string',
            'is_sellable'              => 'nullable|boolean',
            'selling_price'            => 'nullable|numeric|min:0|required_if_accepted:is_sellable',
            'locations'                => 'nullable|array',
            'locations.*.location_id'  => 'required_with:locations|integer',
            'locations.*.is_active'    => 'required_with:locations|boolean',
            'images'                   => 'nullable|array',
            'images.*'                 => 'image|max:5120',
            'remove_images'            => 'nullable|array',
            'remove_images.*'          => 'integer',
            'featured_image_id'        => 'nullable|integer',
            'featured_image_index'     => 'nullable|integer',
        ]);

        $inventoryItem->update($validated);

        $this->syncLocations($inventoryItem, $validated['locations'] ?? null);
        $this->sellable->sync($inventoryItem);

        if (!empty($validated['remove_images'])) {
            $inventoryItem->images()->whereIn('id', $validated['remove_images'])->get()->each->delete();
        }

        $featuredIndex = $request->input('featured_image_index');
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $i => $file) {
                $path = $file->store('inventory', 'public');
                Image::create([
                    'imageable_id'   => $inventoryItem->id,
                    'imageable_type' => InventoryItem::class,
                    'type'           => 'image',
                    'url'            => $path,
                    'is_featured'    => ($featuredIndex !== null && (int) $featuredIndex === $i),
                ]);
            }
        }

        if ($request->filled('featured_image_id')) {
            $inventoryItem->images()->update(['is_featured' => false]);
            $inventoryItem->images()->where('id', $validated['featured_image_id'])->update(['is_featured' => true]);
        }

        return response()->json($inventoryItem->load(['locations', 'product', 'images']));
    }

    public function destroy(InventoryItem $inventoryItem)
    {
        $inventoryItem->delete();

        return response()->json(null, 204);
    }

    /**
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
                'quantity'  => $held[$location['location_id']] ?? 0,
                'is_active' => $location['is_active'],
            ];
        }

        $item->locations()->sync($syncData);

        $item->forceFill([
            'current_stock' => $item->locations()->sum('inventory_item_location.quantity'),
        ])->save();
    }
}
