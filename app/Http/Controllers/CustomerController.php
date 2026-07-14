<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::query();
        
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
        }
        
        $query->with('organization')->latest();

        if ($request->has('nopaginate')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:customers,phone',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'loyalty_points' => 'nullable|integer',
            'tier' => 'nullable|string|max:50',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'organization_name' => 'nullable|string|max:255',
            'google_map_location' => 'nullable|string',
        ]);

        if (empty($validated['organization_id']) && !empty($validated['organization_name'])) {
            $org = Organization::firstOrCreate(['name' => $validated['organization_name']]);
            $validated['organization_id'] = $org->id;
        }

        $customer = Customer::create($validated);
        $customer->load('organization');
        return response()->json($customer, 201);
    }

    public function show(Customer $customer)
    {
        $customer->load('organization');
        return response()->json($customer);
    }

    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:customers,phone,' . $customer->id,
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'loyalty_points' => 'nullable|integer',
            'tier' => 'nullable|string|max:50',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'organization_name' => 'nullable|string|max:255',
            'google_map_location' => 'nullable|string',
        ]);

        if (empty($validated['organization_id']) && !empty($validated['organization_name'])) {
            $org = Organization::firstOrCreate(['name' => $validated['organization_name']]);
            $validated['organization_id'] = $org->id;
        }

        $customer->update($validated);
        $customer->load('organization');
        return response()->json($customer);
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();
        return response()->json(null, 204);
    }
}