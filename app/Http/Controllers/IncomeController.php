<?php

namespace App\Http\Controllers;

use App\Models\Income;
use Illuminate\Http\Request;

class IncomeController extends Controller
{
    public function index()
    {
        return response()->json(Income::with(['location', 'loggedBy', 'header'])->paginate(config('pagination.limit')));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'location_id' => ['nullable', $this->tenantExists('locations')],
            'header_id' => ['nullable', $this->tenantExists('accounting_headers')],
            'category' => 'nullable|string',
            'amount' => 'nullable|numeric',
            'logged_by' => ['nullable', $this->tenantExists('users')],
            'receipt_url' => 'nullable|string',
        ]);

        if (empty($validated['logged_by']) && auth()->check()) {
            $validated['logged_by'] = auth()->id();
        }

        $income = Income::create($validated);
        return response()->json($income, 201);
    }

    public function show(Income $income)
    {
        return response()->json($income->load(['location', 'loggedBy']));
    }

    public function update(Request $request, Income $income)
    {
        $validated = $request->validate([
            'location_id' => ['nullable', $this->tenantExists('locations')],
            'header_id' => ['nullable', $this->tenantExists('accounting_headers')],
            'category' => 'nullable|string',
            'amount' => 'nullable|numeric',
            'logged_by' => ['nullable', $this->tenantExists('users')],
            'receipt_url' => 'nullable|string',
        ]);

        $income->update($validated);
        return response()->json($income);
    }

    public function destroy(Income $income)
    {
        $income->delete();
        return response()->json(null, 204);
    }
}
