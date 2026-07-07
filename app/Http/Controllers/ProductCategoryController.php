<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = ProductCategory::withCount(['products' => function ($query) {
            $query->where('is_active', true);
        }]);

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
            'parent_id' => 'nullable|integer',
            'name' => 'nullable|string',
            'slug' => 'nullable|string',
            'image_url' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $productCategory = ProductCategory::create($validated);
        return response()->json($productCategory, 201);
    }

    public function show(ProductCategory $productCategory)
    {
        return response()->json($productCategory);
    }

    public function update(Request $request, ProductCategory $productCategory)
    {
        $validated = $request->validate([
            'parent_id' => 'nullable|integer',
            'name' => 'nullable|string',
            'slug' => 'nullable|string',
            'image_url' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $productCategory->update($validated);
        return response()->json($productCategory);
    }

    public function destroy(ProductCategory $productCategory)
    {
        $productCategory->delete();
        return response()->json(null, 204);
    }
}