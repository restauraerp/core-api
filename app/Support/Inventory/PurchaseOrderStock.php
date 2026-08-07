<?php

namespace App\Support\Inventory;

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Moves a purchase order's quantities in and out of inventory, and keeps each
 * item's cost per unit at the price the last delivery charged.
 *
 * A delivery lands at one outlet, so the quantities go on the
 * inventory_item_location row for the order's location; the item's
 * `current_stock` is then recomputed from every outlet, so the global figure
 * can never drift from the per-outlet ones.
 *
 * Every mutation of an order goes through reverse-then-apply rather than
 * patching a delta: changing the supplier, the outlet, a quantity or the status
 * is then all the same operation, and `stock_applied` keeps it idempotent - an
 * order that is already out of inventory cannot be taken out twice.
 *
 * Levels themselves are written by StockLevels, which sales go through too.
 */
class PurchaseOrderStock
{
    public function __construct(private readonly StockLevels $levels) {}

    /**
     * Add the order's quantities to inventory, unless they are already there or
     * the order is cancelled.
     */
    public function apply(PurchaseOrder $order): void
    {
        if ($order->stock_applied || ! $order->countsAsStock()) {
            return;
        }

        $this->move($order, 1);

        $order->forceFill(['stock_applied' => true])->save();
    }

    /** Take the order's quantities back out of inventory, if they are in it. */
    public function reverse(PurchaseOrder $order): void
    {
        if (! $order->stock_applied) {
            return;
        }

        $this->move($order, -1);

        $order->forceFill(['stock_applied' => false])->save();
    }

    /**
     * Re-state an order in inventory after it has been edited: whatever it used
     * to contribute is removed, whatever it contributes now is added.
     */
    public function resync(PurchaseOrder $order): void
    {
        $this->reverse($order);
        $this->apply($order->refresh());
    }

    /**
     * Set each item's cost per unit to the price on the most recent delivery of
     * it.
     *
     * Cost is not something a restaurant types in - it is what the last invoice
     * charged. Recomputed from the newest non-cancelled line rather than simply
     * taking the price off the order being saved, so editing a delivery from
     * last month cannot overwrite this week's price, and deleting the newest
     * order falls back to the one before it.
     *
     * @param  iterable<int>  $itemIds
     */
    public function refreshCosts(iterable $itemIds): void
    {
        foreach (array_unique(iterator_to_array($itemIds, false)) as $itemId) {
            $item = InventoryItem::find($itemId);

            if ($item === null) {
                continue;
            }

            $price = PurchaseOrderItem::query()
                ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_items.purchase_order_id')
                ->where('purchase_items.inventory_item_id', $itemId)
                ->where('purchase_orders.status', '!=', PurchaseOrder::CANCELLED)
                ->orderByDesc('purchase_orders.created_at')
                ->orderByDesc('purchase_orders.id')
                ->value('purchase_items.price');

            // No delivery left to price it from - the last known cost stands
            // rather than being blanked.
            if ($price === null) {
                continue;
            }

            $item->forceFill(['cost_per_unit' => $price])->save();
        }
    }

    private function move(PurchaseOrder $order, int $direction): void
    {
        $locationId = $order->location_id;

        DB::transaction(function () use ($order, $direction, $locationId) {
            // Several lines can name the same item, so they are summed before
            // touching inventory rather than written one at a time.
            $quantities = $order->items()
                ->selectRaw('inventory_item_id, SUM(quantity) as total')
                ->groupBy('inventory_item_id')
                ->pluck('total', 'inventory_item_id');

            foreach ($quantities as $itemId => $quantity) {
                $item = InventoryItem::find($itemId);

                if ($item === null) {
                    continue;
                }

                $this->levels->adjust($item, $locationId, $direction * (float) $quantity);
            }
        });
    }
}
