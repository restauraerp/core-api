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

        return response()->json($query->with('header')->orderBy('id', 'desc')->paginate((int) env('PAGINATION_LIMIT', 15)));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer', $this->tenantExists('locations')],
            'transaction_type' => 'nullable|string|max:255',
            'amount' => 'nullable|numeric',
            'reference_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'header_id' => ['nullable', 'integer', $this->tenantExists('accounting_headers')],
        ]);

        $accountingLedger = AccountingLedger::create($validated);

        return response()->json($accountingLedger->load('header'), 201);
    }

    public function show(AccountingLedger $accountingLedger)
    {
        return response()->json($accountingLedger->load('header'));
    }

    public function update(Request $request, AccountingLedger $accountingLedger)
    {
        $validated = $request->validate([
            'location_id' => ['sometimes', 'required', 'integer', $this->tenantExists('locations')],
            'transaction_type' => 'nullable|string|max:255',
            'amount' => 'nullable|numeric',
            'reference_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'header_id' => ['nullable', 'integer', $this->tenantExists('accounting_headers')],
        ]);

        $accountingLedger->update($validated);

        return response()->json($accountingLedger->load('header'));
    }

    public function destroy(AccountingLedger $accountingLedger)
    {
        $accountingLedger->delete();

        return response()->json(null, 204);
    }
}
