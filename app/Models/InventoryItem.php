<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TracksUploadedAssets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Image;

class InventoryItem extends Model
{
    use BelongsToTenant;
    use TracksUploadedAssets;

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
        'sale_unit',
        'sale_units_per_purchase_unit',
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
            'sale_units_per_purchase_unit' => 'decimal:4',
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

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    /**
     * @return list<string>
     */
    public function uploadedAssetColumns(): array
    {
        return ['image'];
    }

    /**
     * How many of the smaller unit fit in one of the counted unit.
     *
     * Never zero: a factor of nothing would make every conversion a division by
     * zero, and the honest reading of "unset" is that the two units are the
     * same thing.
     */
    public function conversionFactor(): float
    {
        $factor = (float) ($this->sale_units_per_purchase_unit ?? 1);

        return $factor > 0 ? $factor : 1.0;
    }

    /** Whether this item is counted in one unit and used in another. */
    public function hasSeparateSaleUnit(): bool
    {
        return $this->sale_unit !== null
            && $this->sale_unit !== ''
            && $this->sale_unit !== $this->unit;
    }

    /**
     * A quantity the kitchen typed, in the unit stock is actually counted in.
     *
     * `$unit` is what the number was entered as - 'sale' for the smaller one,
     * anything else for the counted one.
     */
    public function toPurchaseUnits(float $quantity, ?string $unit): float
    {
        if ($unit !== 'sale' || ! $this->hasSeparateSaleUnit()) {
            return round($quantity, 4);
        }

        return round($quantity / $this->conversionFactor(), 4);
    }
}
