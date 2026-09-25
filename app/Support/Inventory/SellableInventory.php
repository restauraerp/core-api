<?php

namespace App\Support\Inventory;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;

/**
 * Stock sold exactly as it was bought - a bottle of water, a packet of crisps.
 *
 * Ticking "sell at the till" mirrors the item into the product catalogue rather
 * than inventing a second kind of sellable thing: the till, orders, receipts
 * and every sales report already speak product, so one mirrored row buys all of
 * them at once.
 *
 * Unticking never deletes that product. Past orders point at it, and deleting a
 * row that a receipt references would leave sales history with a hole in it -
 * so it is deactivated, which takes it off the till and leaves the history
 * readable.
 */
class SellableInventory
{
    public function __construct(private readonly StockLevels $levels) {}

    /**
     * Bring the item's catalogue entry in line with the item.
     *
     * Safe to call on every save: it creates the product the first time, keeps
     * the name, price, description and image in step afterwards, and switches
     * it off when the item stops being sellable.
     */
    public function sync(InventoryItem $item): void
    {
        $product = $item->product()->first();

        if (! $item->is_sellable) {
            $product?->update(['is_active' => false]);

            return;
        }

        if ($product === null) {
            $product = new Product;
            $product->forceFill(['inventory_item_id' => $item->getKey()]);
        }

        $product->fill([
            'name' => $item->title,
            'description' => $item->description,
            'price' => $item->selling_price ?? 0,
            // Nothing is cooked, so there is no recipe and no preparation - the
            // till hands over what the delivery brought in.
            'type' => 'merchandise',
            'is_active' => true,
        ]);

        $product->save();

        $this->syncImage($item, $product);
        $this->syncAvailability($item, $product);
    }

    /**
     * Take what a finished sale sold off the shelf it was sold from.
     *
     * Two kinds of line move stock: one whose product mirrors a stock item
     * (sold as bought), and a cooked dish, which consumes the ingredients in
     * its recipe. Both are handled here so every caller - create, edit, cancel -
     * stays consistent.
     */
    public function deductForOrder(Order $order): void
    {
        $this->applyStockItems($order, -1);
        $this->applyRecipes($order, -1);
    }

    /** Put back what an order that is being cancelled or deleted took. */
    public function restoreForOrder(Order $order): void
    {
        $this->applyStockItems($order, 1);
        $this->applyRecipes($order, 1);
    }

    /** Move stock for lines whose product is itself an inventory item. */
    private function applyStockItems(Order $order, float $sign): void
    {
        $lines = $order->items()->with('product')->get()
            ->filter(fn ($line) => $line->product?->isStockItem())
            // One order can list the same bottle on two lines.
            ->groupBy(fn ($line) => $line->product->inventory_item_id);

        foreach ($lines as $inventoryItemId => $group) {
            $item = InventoryItem::find($inventoryItemId);

            if ($item === null) {
                continue;
            }

            $this->levels->adjust($item, (int) $order->location_id, $sign * (float) $group->sum('quantity'));
        }
    }

    /**
     * Move stock for the ingredients a cooked line consumes.
     *
     * A recipe's `quantity_required` is stated in the item's usage unit (the
     * unit the kitchen cooks in), while stock is counted in the purchase unit,
     * so the amount is converted with toPurchaseUnits() before it moves. An item
     * with no separate usage unit converts one-to-one. Ingredients shared across
     * lines are summed so the same item moves once.
     */
    private function applyRecipes(Order $order, float $sign): void
    {
        $lines = $order->items()->with('product.recipes.inventoryItem')->get()
            ->filter(fn ($line) => $line->product && $line->product->recipes->isNotEmpty());

        /** @var array<int, array{item: InventoryItem, qty: float}> $deltas */
        $deltas = [];

        foreach ($lines as $line) {
            foreach ($line->product->recipes as $recipe) {
                $item = $recipe->inventoryItem;

                if ($item === null) {
                    continue;
                }

                $usageQty = (float) $recipe->quantity_required * (float) $line->quantity;
                $stockQty = $item->toPurchaseUnits($usageQty, 'sale');

                $deltas[$item->getKey()] ??= ['item' => $item, 'qty' => 0.0];
                $deltas[$item->getKey()]['qty'] += $stockQty;
            }
        }

        foreach ($deltas as $delta) {
            $this->levels->adjust($delta['item'], (int) $order->location_id, $sign * $delta['qty']);
        }
    }

    private function syncImage(InventoryItem $item, Product $product): void
    {
        if (empty($item->image)) {
            return;
        }

        $product->images()->updateOrCreate(
            ['imageable_id' => $product->getKey(), 'imageable_type' => Product::class],
            ['type' => 'image', 'url' => $item->image],
        );
    }

    /**
     * The till only offers a product at outlets where it is available, so the
     * catalogue entry follows the outlets that stock the item.
     */
    private function syncAvailability(InventoryItem $item, Product $product): void
    {
        $availability = [];

        foreach ($item->locations()->get() as $location) {
            $availability[$location->getKey()] = [
                'is_available' => (bool) $location->pivot->is_active,
            ];
        }

        if ($availability !== []) {
            $product->locations()->sync($availability);
        }
    }
}
