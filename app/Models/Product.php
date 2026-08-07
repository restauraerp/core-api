<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use BelongsToTenant;

    protected $casts = [
        'needs_cooking' => 'boolean',
    ];

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'price',
        'sale_price',
        'type',
        'needs_cooking',
        'is_active',
        'recipe_id',
    ];

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * Set when this product is the till-facing face of a stock item sold as
     * bought (a bottle, a packet). Deliberately absent from $fillable: which
     * item a product mirrors is decided by SellableInventory, never by a
     * request body.
     */
    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /** Whether selling this product should take stock off a shelf. */
    public function isStockItem(): bool
    {
        return $this->inventory_item_id !== null;
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    public function locations()
    {
        return $this->scopedToTenantPivot(
            $this->belongsToMany(Location::class, 'location_product')
                ->withPivot('is_available')
                ->withTimestamps()
        );
    }
}
