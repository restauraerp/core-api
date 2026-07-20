<?php

namespace App\Http\Controllers;

use App\Models\AccountingLedger;
use Illuminate\Http\Request;

class AccountingLedgerController extends Controller
{
    public function index(Request $request)
    {
        $query = AccountingLedger::query();

        if ($request->has('location_id') && $request->location_id !== 'all' && $request->location_id !== '') {
            if ($request->location_id === 'general') {
                $query->whereNull('location_id');
            } else {
                $query->where('location_id', $request->location_id);
            }
        }

        if ($request->has('start_date') && $request->start_date) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date') && $request->end_date) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        return response()->json($query->orderBy('id', 'desc')->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            
        ]);

        $accountingLedger = AccountingLedger::create($validated);
        return response()->json($accountingLedger, 201);
    }

    public function show(AccountingLedger $accountingLedger)
    {
        return response()->json($accountingLedger);
    }

    public function update(Request $request, AccountingLedger $accountingLedger)
    {
        $validated = $request->validate([
            
        ]);

        $accountingLedger->update($validated);
        return response()->json($accountingLedger);
    }

    public function destroy(AccountingLedger $accountingLedger)
    {
        $accountingLedger->delete();
        return response()->json(null, 204);
    }
}