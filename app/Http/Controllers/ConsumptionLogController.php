<?php

namespace App\Http\Controllers;

use App\Models\ConsumptionLog;
use App\Models\InventoryItem;
use App\Support\Inventory\StockLevels;
use Illuminate\Http\Request;

class ConsumptionLogController extends Controller
{
    public function __construct(private readonly StockLevels $stock) {}

    public function index(Request $request)
    {
        $query = ConsumptionLog::with(['inventoryItem', 'location', 'loggedBy'])
            ->orderBy('consumed_at', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('inventory_item_id')) {
            $query->where('inventory_item_id', $request->input('inventory_item_id'));
        }

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->input('location_id'));
        }

        if ($request->has('nopaginate')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate(config('pagination.limit')));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'inventory_item_id' => ['required', 'integer', $this->tenantExists('inventory_items')],
            'location_id'       => ['required', 'integer', $this->tenantExists('locations')],
            'quantity'          => 'required|numeric|min:0.001',
            'reason'            => 'nullable|string|max:500',
            'consumed_at'       => 'required|date',
        ]);

        $log = ConsumptionLog::create([
            ...$validated,
            'logged_by' => $request->user()?->id,
        ]);

        // Reduce stock: consumption removes goods from the shelf
        $item = InventoryItem::findOrFail($validated['inventory_item_id']);
        $this->stock->adjust($item, $validated['location_id'], -(float) $validated['quantity']);

        return response()->json($log->load(['inventoryItem', 'location']), 201);
    }

    public function show(ConsumptionLog $consumptionLog)
    {
        return response()->json($consumptionLog->load(['inventoryItem', 'location']));
    }

    public function destroy(ConsumptionLog $consumptionLog)
    {
        // Restore the stock that was consumed
        $this->stock->adjust(
            $consumptionLog->inventoryItem,
            $consumptionLog->location_id,
            (float) $consumptionLog->quantity,
        );

        $consumptionLog->delete();

        return response()->json(null, 204);
    }

    public function trash(Request $request, ConsumptionLog $consumptionLog)
    {
        if (! $request->user()->hasRole(['restaurant_admin', 'super_admin'])) {
            abort(403, 'Only administrators can trash consumption logs.');
        }

        $this->stock->adjust(
            $consumptionLog->inventoryItem,
            $consumptionLog->location_id,
            (float) $consumptionLog->quantity,
        );

        $consumptionLog->update(['trashed_by' => $request->user()->id]);
        $consumptionLog->delete();

        return response()->json(['message' => 'Consumption log trashed and stock restored.']);
    }

    public function restore(Request $request, int $id)
    {
        if (! $request->user()->hasRole(['restaurant_admin', 'super_admin'])) {
            abort(403, 'Only administrators can restore consumption logs.');
        }

        $log = ConsumptionLog::onlyTrashed()->findOrFail($id);

        $log->restore();
        $log->update(['trashed_by' => null]);

        $this->stock->adjust(
            $log->inventoryItem,
            $log->location_id,
            -(float) $log->quantity,
        );

        return response()->json($log->load(['inventoryItem', 'location', 'loggedBy']));
    }

    public function trashed(Request $request)
    {
        if (! $request->user()->hasRole(['restaurant_admin', 'super_admin'])) {
            abort(403, 'Only administrators can view trashed consumption logs.');
        }

        $logs = ConsumptionLog::onlyTrashed()
            ->with(['inventoryItem', 'location', 'loggedBy', 'trashedByUser'])
            ->orderBy('deleted_at', 'desc')
            ->paginate(config('pagination.limit'));

        return response()->json($logs);
    }
}
