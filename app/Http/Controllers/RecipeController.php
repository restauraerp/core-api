<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use Illuminate\Http\Request;

class RecipeController extends Controller
{
    public function index(Request $request)
    {
        $query = Recipe::with(['inventoryItem', 'product']);

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'quantity_required' => 'required|numeric|min:0.01',
        ]);

        // Check if this product already has this ingredient to avoid duplicates
        $existing = Recipe::where('product_id', $validated['product_id'])
            ->where('inventory_item_id', $validated['inventory_item_id'])
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Ingredient already exists in the recipe.'], 409);
        }

        $recipe = Recipe::create($validated);
        $recipe->load(['inventoryItem', 'product']);

        return response()->json($recipe, 201);
    }

    public function show(Recipe $recipe)
    {
        return response()->json($recipe->load(['inventoryItem', 'product']));
    }

    public function update(Request $request, Recipe $recipe)
    {
        $validated = $request->validate([
            'quantity_required' => 'required|numeric|min:0.01',
        ]);

        $recipe->update($validated);
        $recipe->load(['inventoryItem', 'product']);
        
        return response()->json($recipe);
    }

    public function destroy(Recipe $recipe)
    {
        $recipe->delete();
        return response()->json(null, 204);
    }
}