<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    protected $fillable = [
        'title',
        'image',
        'name',
        'sku',
        'unit',
        'min_stock_level',
        'current_stock',
        'cost_per_unit',
    ];

    public function locations()
    {
        return $this->belongsToMany(Location::class, 'inventory_item_location')
                    ->withPivot('quantity', 'is_active')
                    ->withTimestamps();
    }
}
