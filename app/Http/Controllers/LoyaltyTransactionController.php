<?php

namespace App\Http\Controllers;

use App\Models\LoyaltyTransaction;
use Illuminate\Http\Request;

class LoyaltyTransactionController extends Controller
{
    public function index()
    {
        return response()->json(LoyaltyTransaction::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', $this->tenantExists('customers')],
            'order_id' => ['nullable', 'integer', $this->tenantExists('orders')],
            'points' => 'nullable|integer',
            'type' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        $loyaltyTransaction = LoyaltyTransaction::create($validated);

        return response()->json($loyaltyTransaction, 201);
    }

    public function show(LoyaltyTransaction $loyaltyTransaction)
    {
        return response()->json($loyaltyTransaction);
    }

    public function update(Request $request, LoyaltyTransaction $loyaltyTransaction)
    {
        $validated = $request->validate([
            'customer_id' => ['sometimes', 'required', 'integer', $this->tenantExists('customers')],
            'order_id' => ['nullable', 'integer', $this->tenantExists('orders')],
            'points' => 'nullable|integer',
            'type' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        $loyaltyTransaction->update($validated);

        return response()->json($loyaltyTransaction);
    }

    public function destroy(LoyaltyTransaction $loyaltyTransaction)
    {
        $loyaltyTransaction->delete();

        return response()->json(null, 204);
    }
}
