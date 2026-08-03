<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Recipe extends Model
{
    use BelongsToTenant;

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
