<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InventoryItem extends Model
{
    use BelongsToTenant;

    /**
     * current_stock and cost_per_unit are deliberately absent: stock comes from
     * purchase orders and cost is what the last delivery charged. Both are
     * maintained by App\Support\Inventory\PurchaseOrderStock, so neither can be
     * moved by a request body.
     */
    protected $fillable = [
        'title',
        'image',
        'description',
        'sku',
        'unit',
        'min_stock_level',
        'is_sellable',
        'selling_price',
    ];

    protected function casts(): array
    {
        return [
            'is_sellable' => 'boolean',
            'selling_price' => 'decimal:2',
            'current_stock' => 'decimal:2',
            'cost_per_unit' => 'decimal:2',
        ];
    }

    public function locations()
    {
        return $this->scopedToTenantPivot(
            $this->belongsToMany(Location::class, 'inventory_item_location')
                ->withPivot('quantity', 'is_active')
                ->withTimestamps()
        );
    }

    /**
     * The catalogue entry that puts this item on the till, for items sold as
     * bought. Kept in step by App\Support\Inventory\SellableInventory.
     */
    public function product(): HasOne
    {
        return $this->hasOne(Product::class);
    }
}
