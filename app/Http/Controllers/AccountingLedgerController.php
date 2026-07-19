<?php

namespace App\Http\Controllers;

use App\Models\AccountingLedger;
use Illuminate\Http\Request;

class AccountingLedgerController extends Controller
{
    public function index()
    {
        return response()->json(AccountingLedger::latest()->paginate(15));
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