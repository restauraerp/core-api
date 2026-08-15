<?php

namespace App\Http\Controllers;

use App\Models\AccountingHeader;
use Illuminate\Http\Request;

class AccountingHeaderController extends Controller
{
    public function index(Request $request)
    {
        $query = AccountingHeader::query();

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->has('nopaginate')) {
            return response()->json($query->orderBy('name')->get());
        }

        return response()->json($query->orderBy('name')->paginate((int) env('PAGINATION_LIMIT', 15)));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'type'        => 'required|in:income,expense',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
        ]);

        return response()->json(AccountingHeader::create($validated), 201);
    }

    public function show(AccountingHeader $accountingHeader)
    {
        return response()->json($accountingHeader);
    }

    public function update(Request $request, AccountingHeader $accountingHeader)
    {
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'type'        => 'sometimes|in:income,expense',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
        ]);

        $accountingHeader->update($validated);

        return response()->json($accountingHeader);
    }

    public function destroy(AccountingHeader $accountingHeader)
    {
        $accountingHeader->delete();

        return response()->json(null, 204);
    }
}
