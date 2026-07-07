<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index()
    {
        return response()->json(Tag::withCount(['products' => function ($query) {
            $query->where('is_active', true);
        }])->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'nullable|string',
            'slug' => 'nullable|string',
        ]);

        $tag = Tag::create($validated);
        return response()->json($tag, 201);
    }

    public function show(Tag $tag)
    {
        return response()->json($tag);
    }

    public function update(Request $request, Tag $tag)
    {
        $validated = $request->validate([
            'name' => 'nullable|string',
            'slug' => 'nullable|string',
        ]);

        $tag->update($validated);
        return response()->json($tag);
    }

    public function destroy(Tag $tag)
    {
        $tag->delete();
        return response()->json(null, 204);
    }
}