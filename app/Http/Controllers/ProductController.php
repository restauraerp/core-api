<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Image;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with(['images', 'locations']);
        
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }
        
        if ($request->has('nopaginate')) {
            return response()->json($query->get());
        }
        return response()->json($query->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'nullable|integer',
            'name' => 'nullable|string',
            'slug' => 'nullable|string',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric',
            'type' => 'nullable|string',
            'is_active' => 'boolean',
            'recipe_id' => 'nullable|integer',
            'image' => 'nullable|image|max:5120',
            'image_url' => 'nullable|string',
            'locations' => 'nullable|array',
            'locations.*.location_id' => 'required_with:locations|integer',
            'locations.*.is_available' => 'required_with:locations|boolean'
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('foods', 'public');
            $validated['image_url'] = $path;
        }

        $product = Product::create($validated);
        
        if (isset($validated['locations'])) {
            $syncData = [];
            foreach ($validated['locations'] as $loc) {
                $syncData[$loc['location_id']] = ['is_available' => $loc['is_available']];
            }
            $product->locations()->sync($syncData);
        }

        if (isset($validated['image_url']) && !empty($validated['image_url'])) {
            Image::create([
                'imageable_id' => $product->id,
                'imageable_type' => Product::class,
                'type' => 'image',
                'url' => $validated['image_url']
            ]);
        }

        return response()->json($product->load(['images', 'locations']), 201);
    }

    public function show(Product $product)
    {
        return response()->json($product);
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'category_id' => 'nullable|integer',
            'name' => 'nullable|string',
            'slug' => 'nullable|string',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric',
            'type' => 'nullable|string',
            'is_active' => 'boolean',
            'recipe_id' => 'nullable|integer',
            'image' => 'nullable|image|max:5120',
            'image_url' => 'nullable|string',
            'locations' => 'nullable|array',
            'locations.*.location_id' => 'required_with:locations|integer',
            'locations.*.is_available' => 'required_with:locations|boolean'
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('foods', 'public');
            $validated['image_url'] = $path;
        }

        $product->update($validated);

        if (isset($validated['locations'])) {
            $syncData = [];
            foreach ($validated['locations'] as $loc) {
                $syncData[$loc['location_id']] = ['is_available' => $loc['is_available']];
            }
            $product->locations()->sync($syncData);
        }

        if (array_key_exists('image_url', $validated)) {
            if (empty($validated['image_url'])) {
                $product->images()->delete();
            } else {
                $product->images()->updateOrCreate(
                    ['imageable_id' => $product->id, 'imageable_type' => Product::class],
                    ['type' => 'image', 'url' => $validated['image_url']]
                );
            }
        }

        return response()->json($product->load(['images', 'locations']));
    }

    public function destroy(Product $product)
    {
        $product->delete();
        return response()->json(null, 204);
    }
}