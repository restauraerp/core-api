<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    protected $fillable = [
        'product_id',
        'inventory_item_id',
        'quantity_required',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
