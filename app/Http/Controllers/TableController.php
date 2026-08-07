<?php

namespace App\Http\Controllers;

use App\Models\Table;
use App\Support\Orders\OrderFlow;
use Illuminate\Http\Request;

class TableController extends Controller
{
    public function index($locationId)
    {
        return response()->json(Table::where('location_id', $locationId)
            ->withCount(['orders' => function ($query) {
                $query->where(function ($q) {
                    $q->whereNotIn('status', [OrderFlow::SERVED, OrderFlow::DELIVERED, OrderFlow::PACKED, OrderFlow::PICKED_UP])
                        ->orWhere('payment_status', '!=', 'paid');
                });
            }])
            ->get());
    }

    public function store(Request $request, $locationId)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'capacity' => 'nullable|integer|min:1',
        ]);

        $validated['location_id'] = $locationId;

        $table = Table::create($validated);

        return response()->json($table, 201);
    }

    public function show(Table $table)
    {
        return response()->json($table);
    }

    public function update(Request $request, Table $table)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'capacity' => 'nullable|integer|min:1',
        ]);

        $table->update($validated);

        return response()->json($table);
    }

    public function destroy(Table $table)
    {
        $table->delete();

        return response()->json(null, 204);
    }
}
