<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    public function index()
    {
        return response()->json(Delivery::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => ['required', $this->tenantExists('orders')],
            'rider_id' => ['nullable', $this->tenantExists('users')],
            'address' => 'nullable|string',
            'delivery_charge' => 'nullable|numeric',
            'status' => 'required|string',
        ]);

        $delivery = Delivery::create($validated);
        return response()->json($delivery, 201);
    }

    public function show(Delivery $delivery)
    {
        return response()->json($delivery);
    }

    public function update(Request $request, Delivery $delivery)
    {
        $validated = $request->validate([
            'order_id' => ['sometimes', $this->tenantExists('orders')],
            'rider_id' => ['nullable', $this->tenantExists('users')],
            'address' => 'nullable|string',
            'delivery_charge' => 'nullable|numeric',
            'status' => 'sometimes|string',
        ]);

        $delivery->update($validated);
        return response()->json($delivery);
    }

    public function destroy(Delivery $delivery)
    {
        $delivery->delete();
        return response()->json(null, 204);
    }
}