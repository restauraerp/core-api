<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Organization;
use App\Rules\PhoneNumber;
use App\Support\PhoneNumber as PhoneNumberSupport;
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

        return response()->json($query->paginate((int) env('PAGINATION_LIMIT', 15)));
    }

    /**
     * The phone is canonicalised before the rules run, not after.
     *
     * Customer::setPhoneAttribute would normalise it either way, but by then
     * the uniqueness rule has already looked - and it would have looked for
     * `01712345678` while the row it should have found is stored as
     * `+8801712345678`. The duplicate is created, and the second row quietly
     * shadows the first customer's order history.
     */
    private function canonicalisePhone(Request $request): void
    {
        if ($request->filled('phone')) {
            $request->merge([
                'phone' => PhoneNumberSupport::normalise($request->input('phone')) ?? $request->input('phone'),
            ]);
        }
    }

    public function store(Request $request)
    {
        $this->canonicalisePhone($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'max:20', PhoneNumber::mobile(), $this->tenantUnique('customers')],
            'email' => 'nullable|email:rfc,strict|max:255',
            'address' => 'nullable|string',
            'loyalty_points' => 'nullable|integer',
            'tier' => 'nullable|string|max:50',
            'organization_id' => ['nullable', 'integer', $this->tenantExists('organizations')],
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
        $this->canonicalisePhone($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'max:20', PhoneNumber::mobile(), $this->tenantUnique('customers')->ignore($customer->id)],
            'email' => 'nullable|email:rfc,strict|max:255',
            'address' => 'nullable|string',
            'loyalty_points' => 'nullable|integer',
            'tier' => 'nullable|string|max:50',
            'organization_id' => ['nullable', 'integer', $this->tenantExists('organizations')],
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