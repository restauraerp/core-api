<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use Illuminate\Http\Request;

class QuotationController extends Controller
{
    public function index()
    {
        return response()->json(Quotation::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', $this->tenantExists('customers')],
            'location_id' => ['required', 'integer', $this->tenantExists('locations')],
            'created_by' => ['nullable', 'integer', $this->tenantExists('users')],
            'total_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|max:255',
        ]);

        $quotation = Quotation::create($validated);

        return response()->json($quotation, 201);
    }

    public function show(Quotation $quotation)
    {
        return response()->json($quotation);
    }

    public function update(Request $request, Quotation $quotation)
    {
        $validated = $request->validate([
            'customer_id' => ['sometimes', 'required', 'integer', $this->tenantExists('customers')],
            'location_id' => ['sometimes', 'required', 'integer', $this->tenantExists('locations')],
            'created_by' => ['nullable', 'integer', $this->tenantExists('users')],
            'total_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|max:255',
        ]);

        $quotation->update($validated);

        return response()->json($quotation);
    }

    public function destroy(Quotation $quotation)
    {
        $quotation->delete();

        return response()->json(null, 204);
    }
}
