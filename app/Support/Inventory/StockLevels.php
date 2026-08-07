<?php

namespace App\Support\Inventory;

use App\Models\InventoryItem;

/**
 * The one place a stock level changes.
 *
 * Quantities live on the inventory_item_location pivot, one row per outlet, and
 * `inventory_items.current_stock` is recomputed from them after every move - so
 * the headline figure is always the sum of the outlets rather than a second
 * number that can drift.
 *
 * Deliveries (PurchaseOrderStock) add; sales of stock sold as bought
 * (SellableInventory) subtract. Both come through here so there is a single
 * definition of what "on hand" means.
 *
 * Levels are allowed to go negative. A negative level means the books say a
 * restaurant used stock it never received - a real problem worth seeing, and
 * clamping it at zero would only hide the mistake that caused it.
 */
class StockLevels
{
    public function adjust(InventoryItem $item, int $locationId, float $quantity): void
    {
        if ($quantity == 0.0) {
            return;
        }

        $existing = $item->locations()->where('locations.id', $locationId)->first();

        if ($existing === null) {
            $item->locations()->attach($locationId, [
                'quantity' => $quantity,
                'is_active' => true,
            ]);
        } else {
            // is_active is left alone: an outlet that has been switched off
            // still holds whatever was delivered to it.
            $item->locations()->updateExistingPivot($locationId, [
                'quantity' => (float) $existing->pivot->quantity + $quantity,
            ]);
        }

        $item->forceFill([
            'current_stock' => $item->locations()->sum('inventory_item_location.quantity'),
        ])->save();
    }
}
