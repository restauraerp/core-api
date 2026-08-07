<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use BelongsToTenant;

    /**
     * The model name says purchase_order_items; the table has always been
     * purchase_items. Left unset, every query hit a table that does not
     * exist.
     */
    protected $table = 'purchase_items';

    /**
     * tenant_id is deliberately absent - BelongsToTenant stamps it, and
     * listing it here would let a request body move this row to another
     * restaurant.
     *
     * `price` is the price of one unit, not the row total. The row total is
     * derived, never stored, so the two can never disagree.
     */
    protected $fillable = [
        'purchase_order_id',
        'inventory_item_id',
        'quantity',
        'price',
    ];

    protected $appends = ['line_total'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'price' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    protected function lineTotal(): Attribute
    {
        return Attribute::get(fn () => round((float) $this->quantity * (float) $this->price, 2));
    }
}
