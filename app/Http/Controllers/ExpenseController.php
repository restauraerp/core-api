<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index()
    {
        return response()->json(Expense::with(['location', 'loggedBy', 'header'])->paginate(config('pagination.limit')));
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
            'location_id' => ['nullable', $this->tenantExists('locations')],
            'header_id' => ['nullable', $this->tenantExists('accounting_headers')],
            'category' => 'nullable|string',
            'amount' => 'nullable|numeric',
            'logged_by' => ['nullable', $this->tenantExists('users')],
            'receipt_url' => 'nullable|string',
        ]);

        $expense->update($validated);

        return response()->json($expense);
    }

    public function destroy(Request $request, Expense $expense)
    {
        $expense->update(['trashed_by' => $request->user()?->id]);
        $expense->delete();

        return response()->json(null, 204);
    }

    public function trashed(Request $request)
    {
        $query = Expense::onlyTrashed()
            ->with(['location', 'loggedBy', 'header', 'trashedByUser']);

        if ($request->has('location_id') && $request->filled('location_id')) {
            $query->where('location_id', $request->input('location_id'));
        }

        return response()->json(
            $query->orderBy('deleted_at', 'desc')
                ->paginate(config('pagination.limit'))
        );
    }

    public function restore(Request $request, int $id)
    {
        $expense = Expense::onlyTrashed()->findOrFail($id);
        $expense->restore();
        $expense->update(['trashed_by' => null]);

        return response()->json($expense->load(['location', 'loggedBy', 'header', 'trashedByUser']));
    }
}
