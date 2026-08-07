<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Support\Inventory\PurchaseOrderStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Single line rows of a purchase order.
 *
 * The order controller writes whole documents, which is how the admin screens
 * work. These endpoints exist for the one-row edits an integration wants to
 * make, and they carry the same two obligations: the order's total is
 * recomputed from its lines, and inventory is re-stated to match.
 */
class PurchaseOrderItemController extends Controller
{
    public function __construct(private readonly PurchaseOrderStock $stock) {}

    public function index(PurchaseOrder $purchaseOrder)
    {
        return response()->json($purchaseOrder->items()->with('inventoryItem')->get());
    }

    public function store(Request $request, PurchaseOrder $purchaseOrder)
    {
        $validated = $request->validate([
            'inventory_item_id' => ['required', 'integer', $this->tenantExists('inventory_items')],
            'quantity' => 'required|numeric|min:0.01',
            'price' => 'required|numeric|min:0',
        ]);

        $item = DB::transaction(function () use ($purchaseOrder, $validated) {
            $this->stock->reverse($purchaseOrder);

            $item = $purchaseOrder->items()->create($validated);

            $this->settle($purchaseOrder);

            return $item;
        });

        return response()->json($item->load('inventoryItem'), 201);
    }

    public function show(PurchaseOrderItem $purchaseOrderItem)
    {
        return response()->json($purchaseOrderItem->load('inventoryItem'));
    }

    public function update(Request $request, PurchaseOrderItem $purchaseOrderItem)
    {
        $validated = $request->validate([
            'inventory_item_id' => ['sometimes', 'required', 'integer', $this->tenantExists('inventory_items')],
            'quantity' => 'sometimes|required|numeric|min:0.01',
            'price' => 'sometimes|required|numeric|min:0',
        ]);

        DB::transaction(function () use ($validated, $purchaseOrderItem) {
            $order = $purchaseOrderItem->purchaseOrder;
            // Repointing a line at another item leaves the old one priced from
            // a delivery it is no longer part of.
            $previousItemId = $purchaseOrderItem->inventory_item_id;

            $this->stock->reverse($order);
            $purchaseOrderItem->update($validated);
            $this->settle($order, [$previousItemId]);
        });

        return response()->json($purchaseOrderItem->load('inventoryItem'));
    }

    public function destroy(PurchaseOrderItem $purchaseOrderItem)
    {
        DB::transaction(function () use ($purchaseOrderItem) {
            $order = $purchaseOrderItem->purchaseOrder;
            $removedItemId = $purchaseOrderItem->inventory_item_id;

            $this->stock->reverse($order);
            $purchaseOrderItem->delete();
            $this->settle($order, [$removedItemId]);
        });

        return response()->json(null, 204);
    }

    /** Bring the order's total, the inventory levels and the costs back in line. */
    private function settle(PurchaseOrder $order, array $alsoReprice = []): void
    {
        $lines = $order->items()->get();

        $order->forceFill([
            'total_amount' => round((float) $lines->sum(fn (PurchaseOrderItem $item) => $item->line_total), 2),
        ])->save();

        $this->stock->apply($order->refresh());
        $this->stock->refreshCosts([...$lines->pluck('inventory_item_id')->all(), ...$alsoReprice]);
    }
}
