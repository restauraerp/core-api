<?php

namespace App\Http\Controllers;

use App\Enums\LocationType;
use App\Models\Location;
use App\Models\Order;
use App\Rules\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\Response;

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
            'phone' => ['nullable', 'string', 'max:50', PhoneNumber::any()],
            'email' => 'nullable|email:rfc,strict|max:255',
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
            'phone' => ['nullable', 'string', 'max:50', PhoneNumber::any()],
            'email' => 'nullable|email:rfc,strict|max:255',
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

    /**
     * Deleting an outlet is not an undo button, and used to behave like one.
     *
     * Twelve tables carry `location_id` with `cascadeOnDelete`, and `orders` is
     * one of them - which cascades again to `order_items` and `payments`.
     * Locations are not soft-deleted, so this was a hard delete of a
     * restaurant's entire trading history behind a confirm() that only asked
     * "are you sure you want to delete Banani Branch?". Against the local demo
     * tenant that one click removed 10,269 orders and 10,261 payments, with
     * nothing to restore from.
     *
     * It matters more now than it did: with the outlet picker hidden for
     * single-outlet restaurants, most owners never think about locations at
     * all, so the Locations screen becomes an unfamiliar place where a stray
     * trash icon looks harmless.
     *
     * So two refusals, both recoverable and both explaining themselves:
     *
     *  - The last outlet cannot go. A restaurant with nowhere to sell from is a
     *    broken account, not a configured one - the same reasoning that keeps
     *    `update_location` in Modules::ESSENTIAL.
     *  - An outlet that has traded cannot go. `is_active` already exists and is
     *    what retiring a branch means: it stops appearing to staff and keeps
     *    every order that was rung through it.
     */
    public function destroy(Location $location)
    {
        if (Location::query()->count() <= 1) {
            return response()->json([
                'message' => 'This is your only outlet, and a restaurant needs somewhere to sell from. Add another outlet before removing this one.',
                'error' => 'last_location',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Trashed orders count too: they are still rows, and the database
        // cascade does not care that the application considers them deleted.
        $orders = Order::withTrashed()->where('location_id', $location->getKey())->count();

        if ($orders > 0) {
            return response()->json([
                'message' => "This outlet has {$orders} order(s) against it. Deleting it would delete them, and their payments, permanently. Switch the outlet off instead to stop staff selling from it while keeping its history.",
                'error' => 'location_has_orders',
                'orders' => $orders,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $location->delete();

        return response()->json(null, 204);
    }
}
