<?php

namespace App\Http\Controllers;

use App\Models\ProductMedia;
use Illuminate\Http\Request;

class ProductMediaController extends Controller
{
    public function index()
    {
        return response()->json(ProductMedia::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'nullable|integer',
            'media_type' => 'nullable|string',
            'media_url' => 'nullable|string',
            'is_primary' => 'boolean',
        ]);

        $productMedia = ProductMedia::create($validated);
        return response()->json($productMedia, 201);
    }

    public function show(ProductMedia $productMedia)
    {
        return response()->json($productMedia);
    }

    public function update(Request $request, ProductMedia $productMedia)
    {
        $validated = $request->validate([
            'product_id' => 'nullable|integer',
            'media_type' => 'nullable|string',
            'media_url' => 'nullable|string',
            'is_primary' => 'boolean',
        ]);

        $productMedia->update($validated);
        return response()->json($productMedia);
    }

    public function destroy(ProductMedia $productMedia)
    {
        $productMedia->delete();
        return response()->json(null, 204);
    }
}