<?php

namespace App\Http\Controllers;

use App\Models\SocialLink;
use Illuminate\Http\Request;

class SocialLinkController extends Controller
{
    public function index()
    {
        return response()->json(SocialLink::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'platform' => 'required|string|max:255',
            'url' => 'required|url',
            'is_active' => 'boolean',
        ]);

        $link = SocialLink::create($validated);
        return response()->json($link, 201);
    }

    public function show(SocialLink $socialLink)
    {
        return response()->json($socialLink);
    }

    public function update(Request $request, SocialLink $socialLink)
    {
        $validated = $request->validate([
            'platform' => 'sometimes|string|max:255',
            'url' => 'sometimes|url',
            'is_active' => 'boolean',
        ]);

        $socialLink->update($validated);
        return response()->json($socialLink);
    }

    public function destroy(SocialLink $socialLink)
    {
        $socialLink->delete();
        return response()->json(null, 204);
    }
}
