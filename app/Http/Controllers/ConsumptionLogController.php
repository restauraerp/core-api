<?php

namespace App\Http\Controllers;

use App\Models\ConsumptionLog;
use App\Models\InventoryItem;
use App\Support\Inventory\StockLevels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            // Which unit the number was typed in. An item bought by the sack
            // and cooked with by the kilo is entered either way.
            'entry_unit'        => ['nullable', Rule::in(['purchase', 'sale'])],
            'reason'            => 'nullable|string|max:500',
            'consumed_at'       => 'required|date',
        ]);

        $item = InventoryItem::findOrFail($validated['inventory_item_id']);
        $stockQuantity = $item->toPurchaseUnits((float) $validated['quantity'], $validated['entry_unit'] ?? null);

        $log = ConsumptionLog::create([
            ...$validated,
            'stock_quantity' => $stockQuantity,
            'logged_by' => $request->user()?->id,
        ]);

        // Reduce stock, in the unit stock is counted in.
        $this->stock->adjust($item, $validated['location_id'], -$stockQuantity);

        return response()->json($log->load(['inventoryItem', 'location']), 201);
    }

    /**
     * Several items consumed in one go.
     *
     * A cook closing the kitchen writes off eight things at once, and doing
     * that one form submission at a time is how a restaurant stops bothering.
     * Wrapped in a transaction: eight rows that half-saved would leave stock
     * wrong in a way nobody could see, which is worse than a refusal.
     */
    public function storeBatch(Request $request)
    {
        $validated = $request->validate([
            'entries' => 'required|array|min:1|max:100',
            'entries.*.inventory_item_id' => ['required', 'integer', $this->tenantExists('inventory_items')],
            'entries.*.location_id' => ['required', 'integer', $this->tenantExists('locations')],
            'entries.*.quantity' => 'required|numeric|min:0.001',
            'entries.*.entry_unit' => ['nullable', Rule::in(['purchase', 'sale'])],
            'entries.*.reason' => 'nullable|string|max:500',
            'entries.*.consumed_at' => 'required|date',
        ], [
            'entries.required' => 'Add at least one item to report.',
            'entries.*.quantity.min' => 'A consumed quantity has to be more than zero.',
        ]);

        $logs = DB::transaction(function () use ($validated, $request) {
            $created = [];

            foreach ($validated['entries'] as $entry) {
                $item = InventoryItem::findOrFail($entry['inventory_item_id']);
                $stockQuantity = $item->toPurchaseUnits((float) $entry['quantity'], $entry['entry_unit'] ?? null);

                $log = ConsumptionLog::create([
                    ...$entry,
                    'stock_quantity' => $stockQuantity,
                    'logged_by' => $request->user()?->id,
                ]);

                $this->stock->adjust($item, $entry['location_id'], -$stockQuantity);

                $created[] = $log;
            }

            return $created;
        });

        return response()->json([
            'created' => count($logs),
            'data' => collect($logs)->map->load(['inventoryItem', 'location']),
        ], 201);
    }

    /**
     * Corrects a log, and the stock it moved.
     *
     * Admin only, like trashing one, and for the same reason: this figure is
     * what took goods off the shelf, so changing it moves them again.
     *
     * Stock is adjusted by the difference rather than recomputed. The item or
     * the outlet can change too, and when either does the old line has to be
     * put back where it came from before the new one is taken - otherwise a log
     * moved from Banani to Gulshan silently leaves Banani short.
     */
    public function update(Request $request, ConsumptionLog $consumptionLog)
    {
        if (! $request->user()->hasRole(['restaurant_admin', 'super_admin'])) {
            abort(403, 'Only administrators can edit consumption logs.');
        }

        $validated = $request->validate([
            'inventory_item_id' => ['sometimes', 'integer', $this->tenantExists('inventory_items')],
            'location_id' => ['sometimes', 'integer', $this->tenantExists('locations')],
            'quantity' => 'sometimes|numeric|min:0.001',
            'entry_unit' => ['sometimes', Rule::in(['purchase', 'sale'])],
            'reason' => 'nullable|string|max:500',
            'consumed_at' => 'sometimes|date',
        ]);

        return DB::transaction(function () use ($validated, $consumptionLog, $request) {
            $wasItem = $consumptionLog->inventoryItem;
            $wasLocation = $consumptionLog->location_id;
            $wasQuantity = $consumptionLog->stockQuantity();

            $consumptionLog->fill($validated);

            // Kept from before the first correction only, so the trail says what
            // the log originally claimed rather than what it said last time.
            if ($consumptionLog->original_quantity === null) {
                $consumptionLog->original_quantity = $wasQuantity;
            }

            $consumptionLog->edited_by = $request->user()->id;
            $consumptionLog->edited_at = now();
            // Recomputed, because the quantity, the unit or the item may all
            // have changed and stock moves in purchase units whatever was typed.
            $consumptionLog->stock_quantity = $consumptionLog->inventoryItem
                ->toPurchaseUnits((float) $consumptionLog->quantity, $consumptionLog->entry_unit);
            $consumptionLog->save();

            $nowItem = $consumptionLog->fresh()->inventoryItem;
            $nowLocation = $consumptionLog->location_id;
            $nowQuantity = $consumptionLog->stockQuantity();

            if ($wasItem?->id === $nowItem?->id && $wasLocation === $nowLocation) {
                // Same shelf: move only the difference.
                $this->stock->adjust($nowItem, $nowLocation, $wasQuantity - $nowQuantity);
            } else {
                // Different shelf: put the old back, take the new.
                if ($wasItem !== null) {
                    $this->stock->adjust($wasItem, $wasLocation, $wasQuantity);
                }

                $this->stock->adjust($nowItem, $nowLocation, -$nowQuantity);
            }

            return response()->json(
                $consumptionLog->load(['inventoryItem', 'location', 'loggedBy', 'editedByUser']),
            );
        });
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
            $consumptionLog->stockQuantity(),
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
            $consumptionLog->stockQuantity(),
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
            -$log->stockQuantity(),
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
