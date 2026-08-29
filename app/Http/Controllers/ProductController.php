<?php

namespace App\Http\Controllers;

use App\Models\Image;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    private function pageSize(): int
    {
        return config('pagination.limit');
    }

    public function index(Request $request)
    {
        $query = Product::with(['images', 'locations', 'category', 'comboItems']);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        if ($request->filled('needs_cooking')) {
            $query->where('needs_cooking', (bool) $request->input('needs_cooking'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('exclude_type')) {
            $query->where('type', '!=', $request->input('exclude_type'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', (bool) $request->input('is_active'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        $allowedSorts = ['name', 'price'];
        $sort = in_array($request->input('sort'), $allowedSorts) ? $request->input('sort') : 'id';
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $direction);

        if ($request->has('nopaginate')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate($this->pageSize()));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id'              => 'nullable|integer',
            'name'                     => 'nullable|string',
            'slug'                     => 'nullable|string',
            'description'              => 'nullable|string',
            'price'                    => 'nullable|numeric',
            'sale_price'               => 'nullable|numeric',
            'type'                     => 'nullable|string',
            'needs_cooking'            => 'nullable|boolean',
            'is_active'                => 'boolean',
            'recipe_id'                => 'nullable|integer',
            'locations'                => 'nullable|array',
            'locations.*.location_id'  => 'required_with:locations|integer',
            'locations.*.is_available' => 'required_with:locations|boolean',
            'images'                   => 'nullable|array',
            'images.*'                 => 'image|max:5120',
            'featured_image_index'     => 'nullable|integer',
            'combo_items'              => 'nullable|array',
            'combo_items.*.product_id' => 'nullable|integer',
            'combo_items.*.inventory_item_id' => 'nullable|integer',
            'combo_items.*.quantity'   => 'nullable|numeric',
        ]);

        $product = Product::create($validated);

        if (isset($validated['locations'])) {
            $syncData = [];
            foreach ($validated['locations'] as $loc) {
                $syncData[$loc['location_id']] = ['is_available' => $loc['is_available']];
            }
            $product->locations()->sync($syncData);
        }

        if ($request->has('combo_items') && is_array($request->input('combo_items'))) {
            foreach ($request->input('combo_items') as $item) {
                $product->comboItems()->create([
                    'product_id'        => $item['product_id'] ?? null,
                    'inventory_item_id' => $item['inventory_item_id'] ?? null,
                    'quantity'          => $item['quantity'] ?? 1,
                ]);
            }
        }

        $featuredIndex = $request->input('featured_image_index');

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $i => $file) {
                $path = $file->store('foods', 'public');
                Image::create([
                    'imageable_id'   => $product->id,
                    'imageable_type' => Product::class,
                    'type'           => 'image',
                    'url'            => $path,
                    'is_featured'    => ($featuredIndex !== null && (int) $featuredIndex === $i),
                ]);
            }
        }

        return response()->json($product->load(['images', 'locations', 'comboItems']), 201);
    }

    public function show(Product $product)
    {
        return response()->json($product->load(['images', 'locations', 'comboItems']));
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'category_id'              => 'nullable|integer',
            'name'                     => 'nullable|string',
            'slug'                     => 'nullable|string',
            'description'              => 'nullable|string',
            'price'                    => 'nullable|numeric',
            'sale_price'               => 'nullable|numeric',
            'type'                     => 'nullable|string',
            'needs_cooking'            => 'nullable|boolean',
            'is_active'                => 'boolean',
            'recipe_id'                => 'nullable|integer',
            'locations'                => 'nullable|array',
            'locations.*.location_id'  => 'required_with:locations|integer',
            'locations.*.is_available' => 'required_with:locations|boolean',
            'images'                   => 'nullable|array',
            'images.*'                 => 'image|max:5120',
            'remove_images'            => 'nullable|array',
            'remove_images.*'          => 'integer',
            'featured_image_id'        => 'nullable|integer',
            'featured_image_index'     => 'nullable|integer',
            'combo_items'              => 'nullable|array',
            'combo_items.*.product_id' => 'nullable|integer',
            'combo_items.*.inventory_item_id' => 'nullable|integer',
            'combo_items.*.quantity'   => 'nullable|numeric',
        ]);

        $product->update($validated);

        if (isset($validated['locations'])) {
            $syncData = [];
            foreach ($validated['locations'] as $loc) {
                $syncData[$loc['location_id']] = ['is_available' => $loc['is_available']];
            }
            $product->locations()->sync($syncData);
        }

        if ($request->has('combo_items')) {
            $product->comboItems()->delete();
            $items = $request->input('combo_items');
            if (is_array($items)) {
                foreach ($items as $item) {
                    $product->comboItems()->create([
                        'product_id'        => $item['product_id'] ?? null,
                        'inventory_item_id' => $item['inventory_item_id'] ?? null,
                        'quantity'          => $item['quantity'] ?? 1,
                    ]);
                }
            }
        }

        // Remove requested images
        if (!empty($validated['remove_images'])) {
            $product->images()->whereIn('id', $validated['remove_images'])->get()->each->delete();
        }

        // Attach new images
        $featuredIndex = $request->input('featured_image_index');
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $i => $file) {
                $path = $file->store('foods', 'public');
                Image::create([
                    'imageable_id'   => $product->id,
                    'imageable_type' => Product::class,
                    'type'           => 'image',
                    'url'            => $path,
                    'is_featured'    => ($featuredIndex !== null && (int) $featuredIndex === $i),
                ]);
            }
        }

        // Update featured flag on existing images
        if ($request->filled('featured_image_id')) {
            $product->images()->update(['is_featured' => false]);
            $product->images()->where('id', $validated['featured_image_id'])->update(['is_featured' => true]);
        }

        return response()->json($product->load(['images', 'locations', 'comboItems']));
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->json(null, 204);
    }
}
