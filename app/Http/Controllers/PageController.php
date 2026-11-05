<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Support\Site\HtmlSanitizer;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function index()
    {
        return response()->json(Page::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:255', $this->tenantUnique('pages')],
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
        ]);

        // The storefront renders this HTML as it is - store it clean.
        $validated['content'] = HtmlSanitizer::clean($validated['content'] ?? null);

        $page = Page::create($validated);
        return response()->json($page, 201);
    }

    public function show(Page $page)
    {
        return response()->json($page);
    }

    public function update(Request $request, Page $page)
    {
        $validated = $request->validate([
            'slug' => ['sometimes', 'string', 'max:255', $this->tenantUnique('pages')->ignore($page->id)],
            'title' => 'sometimes|string|max:255',
            'content' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
        ]);

        if (array_key_exists('content', $validated)) {
            $validated['content'] = HtmlSanitizer::clean($validated['content']);
        }

        $page->update($validated);
        return response()->json($page);
    }

    public function destroy(Page $page)
    {
        $page->delete();
        return response()->json(null, 204);
    }
}
