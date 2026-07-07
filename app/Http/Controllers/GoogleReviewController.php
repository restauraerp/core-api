<?php

namespace App\Http\Controllers;

use App\Models\GoogleReview;
use Illuminate\Http\Request;

class GoogleReviewController extends Controller
{
    public function index()
    {
        return response()->json(GoogleReview::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'author_name' => 'required|string|max:255',
            'rating' => 'required|integer|min:1|max:5',
            'text' => 'nullable|string',
            'time' => 'nullable|date',
            'is_displayed' => 'boolean',
        ]);

        $review = GoogleReview::create($validated);
        return response()->json($review, 201);
    }

    public function show(GoogleReview $googleReview)
    {
        return response()->json($googleReview);
    }

    public function update(Request $request, GoogleReview $googleReview)
    {
        $validated = $request->validate([
            'author_name' => 'sometimes|string|max:255',
            'rating' => 'sometimes|integer|min:1|max:5',
            'text' => 'nullable|string',
            'time' => 'nullable|date',
            'is_displayed' => 'boolean',
        ]);

        $googleReview->update($validated);
        return response()->json($googleReview);
    }

    public function destroy(GoogleReview $googleReview)
    {
        $googleReview->delete();
        return response()->json(null, 204);
    }
}
