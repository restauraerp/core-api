<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ComboItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'combo_product_id',
        'product_id',
        'inventory_item_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'float',
    ];

    public function comboProduct()
    {
        return $this->belongsTo(Product::class, 'combo_product_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
