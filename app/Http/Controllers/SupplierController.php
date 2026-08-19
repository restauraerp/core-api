<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Rules\PhoneNumber;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index()
    {
        return response()->json(Supplier::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'phone' => ['nullable', 'string', 'max:50', PhoneNumber::any()],
            'email' => 'nullable|email:rfc,strict|max:255',
            'address' => 'nullable|string',
        ]);

        $supplier = Supplier::create($validated);

        return response()->json($supplier, 201);
    }

    public function show(Supplier $supplier)
    {
        return response()->json($supplier);
    }

    public function update(Request $request, Supplier $supplier)
    {
        // `sometimes` so a partial update does not blank the fields it omits,
        // while `name` still cannot be sent empty.
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'phone' => ['nullable', 'string', 'max:50', PhoneNumber::any()],
            'email' => 'nullable|email:rfc,strict|max:255',
            'address' => 'nullable|string',
        ]);

        $supplier->update($validated);

        return response()->json($supplier);
    }

    public function destroy(Supplier $supplier)
    {
        $supplier->delete();

        return response()->json(null, 204);
    }
}
