<?php

namespace App\Http\Controllers;

use App\Services\DeliveryPersonService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

class DeliveryPersonController extends Controller
{
    /**
     * List cached delivery persons for the current tenant.
     * Optionally filters by location_id (floating riders with null location_id are always included).
     */
    public function index(Request $request)
    {
        $tenantId = app(TenantContext::class)->id();
        if (! $tenantId) {
            return response()->json([]);
        }

        $riders = DeliveryPersonService::getForTenant($tenantId);

        if ($request->has('location_id') && $request->filled('location_id')) {
            $locationId = (int) $request->input('location_id');
            $riders = $riders->filter(function ($rider) use ($locationId) {
                $location = data_get($rider, 'location_id');
                return $location === null || (int) $location === $locationId;
            })->values();
        }

        return response()->json($riders);
    }
}
