<?php

namespace App\Http\Controllers;

use App\Enums\LocationType;
use App\Models\Location;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class LocationController extends Controller
{
    public function index()
    {
        return response()->json(Location::with(['halls', 'tables', 'cctvCameras', 'images', 'videos', 'featuredImage', 'featuredVideo'])->get());
    }

    public function types()
    {
        return response()->json(LocationType::options());
    }

    public function store(Request $request)
    {
        // The outlet cap is what a customer is actually buying between Starter
        // and Business, and until now it was declared on the tenant and never
        // checked - one restaurant was running five outlets on a two-outlet
        // plan. Enforced here rather than in validation because it is not a
        // problem with the request: the payload is fine, the subscription is
        // not.
        $tenant = app(TenantContext::class)->get();

        if ($tenant !== null && $tenant->hasReachedOutletLimit()) {
            return response()->json([
                'message' => 'Your plan does not include another outlet.',
                'error' => 'outlet_limit_reached',
                'plan' => $tenant->plan,
                'plan_name' => $tenant->planLabel(),
                'outlet_limit' => $tenant->outletLimit(),
                'outlets_used' => $tenant->locations()->count(),
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['nullable', 'string', new Enum(LocationType::class)],
            'address' => 'nullable|string',
            'map_url' => 'nullable|url',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
            'featured_image' => 'nullable|image|max:5120',
            'featured_video' => 'nullable|mimes:mp4,mov,ogg,qt|max:20480',
            'images.*' => 'nullable|image|max:5120',
            'videos.*' => 'nullable|mimes:mp4,mov,ogg,qt|max:20480',
        ]);

        $location = Location::create($validated);

        if ($request->hasFile('featured_image')) {
            $path = $request->file('featured_image')->store('locations', 'public');
            $location->featuredImage()->create(['url' => $path, 'type' => 'featured_image']);
        }

        if ($request->hasFile('featured_video')) {
            $path = $request->file('featured_video')->store('locations_videos', 'public');
            $location->featuredVideo()->create(['url' => $path, 'type' => 'featured_video']);
        }

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                $path = $file->store('locations', 'public');
                $location->images()->create(['url' => $path, 'type' => 'image']);
            }
        }
        if ($request->hasFile('videos')) {
            foreach ($request->file('videos') as $file) {
                $path = $file->store('locations_videos', 'public');
                $location->videos()->create(['url' => $path, 'type' => 'video']);
            }
        }

        return response()->json($location->load(['images', 'videos', 'featuredImage', 'featuredVideo']), 201);
    }

    public function show($identifier)
    {
        $location = Location::where('id', $identifier)->orWhere('slug', $identifier)->firstOrFail();

        return response()->json($location->load(['halls', 'tables', 'cctvCameras', 'images', 'videos', 'featuredImage', 'featuredVideo']));
    }

    public function update(Request $request, Location $location)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => ['nullable', 'string', new Enum(LocationType::class)],
            'address' => 'nullable|string',
            'map_url' => 'nullable|url',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'is_active' => 'boolean',
            'featured_image' => 'nullable|image|max:5120',
            'featured_video' => 'nullable|mimes:mp4,mov,ogg,qt|max:20480',
            'images.*' => 'nullable|image|max:5120',
            'videos.*' => 'nullable|mimes:mp4,mov,ogg,qt|max:20480',
        ]);

        $location->update($validated);

        if ($request->hasFile('featured_image')) {
            if ($location->featuredImage) {
                $location->featuredImage->delete();
            }
            $path = $request->file('featured_image')->store('locations', 'public');
            $location->featuredImage()->create(['url' => $path, 'type' => 'featured_image']);
        }

        if ($request->hasFile('featured_video')) {
            if ($location->featuredVideo) {
                $location->featuredVideo->delete();
            }
            $path = $request->file('featured_video')->store('locations_videos', 'public');
            $location->featuredVideo()->create(['url' => $path, 'type' => 'featured_video']);
        }

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                $path = $file->store('locations', 'public');
                $location->images()->create(['url' => $path, 'type' => 'image']);
            }
        }
        if ($request->hasFile('videos')) {
            foreach ($request->file('videos') as $file) {
                $path = $file->store('locations_videos', 'public');
                $location->videos()->create(['url' => $path, 'type' => 'video']);
            }
        }

        return response()->json($location->load(['images', 'videos', 'featuredImage', 'featuredVideo']));
    }

    public function destroy(Location $location)
    {
        $location->delete();

        return response()->json(null, 204);
    }
}
