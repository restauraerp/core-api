<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = ProductCategory::withCount(['products' => function ($query) {
            $query->where('is_active', true);
        }])->ordered();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->has('nopaginate')) {
            return response()->json($query->get());
        }
        return response()->json($query->paginate(config('pagination.limit')));
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

        // A new category goes to the end of the restaurant's order.
        $validated['sort_order'] = (int) ProductCategory::max('sort_order') + 1;

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

    /**
     * PUT /product-categories/reorder - the restaurant's whole category order
     * at once, as the list of ids top to bottom.
     *
     * The complete list, not a single move: the screen sends what it shows, so
     * two people reordering at once cannot leave gaps or duplicate positions -
     * the last save wins, whole.
     */
    public function reorder(Request $request)
    {
        if (! $request->user()->can('update_catalog_item')) {
            abort(403, 'You do not have permission to change the catalog.');
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $ids = array_map('intval', $validated['ids']);
        $known = ProductCategory::pluck('id')->all();

        sort($known);
        $given = $ids;
        sort($given);

        if ($given !== $known) {
            throw ValidationException::withMessages([
                'ids' => ['The list is out of date - a category was added or removed. Reload and try again.'],
            ]);
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $position => $id) {
                ProductCategory::whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });

        return response()->json(ProductCategory::ordered()->get(['id', 'name', 'sort_order']));
    }

    public function destroy(ProductCategory $productCategory)
    {
        $productCategory->delete();
        return response()->json(null, 204);
    }
}