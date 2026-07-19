<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index()
    {
        return response()->json(Expense::with(['location', 'loggedBy'])->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'location_id' => 'nullable|exists:locations,id',
            'category' => 'nullable|string',
            'amount' => 'nullable|numeric',
            'logged_by' => 'nullable|exists:users,id',
            'receipt_url' => 'nullable|string',
        ]);

        if (empty($validated['logged_by']) && auth()->check()) {
            $validated['logged_by'] = auth()->id();
        }

        $expense = Expense::create($validated);
        return response()->json($expense, 201);
    }

    public function show(Expense $expense)
    {
        return response()->json($expense->load(['location', 'loggedBy']));
    }

    public function update(Request $request, Expense $expense)
    {
        $validated = $request->validate([
            'location_id' => 'nullable|exists:locations,id',
            'category' => 'nullable|string',
            'amount' => 'nullable|numeric',
            'logged_by' => 'nullable|exists:users,id',
            'receipt_url' => 'nullable|string',
        ]);

        $expense->update($validated);
        return response()->json($expense);
    }

    public function destroy(Expense $expense)
    {
        $expense->delete();
        return response()->json(null, 204);
    }
}