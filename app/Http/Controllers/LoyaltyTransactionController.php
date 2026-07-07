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