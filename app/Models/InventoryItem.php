<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class InventoryItem extends Model
{
    use BelongsToTenant;

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
        return $this->scopedToTenantPivot(
            $this->belongsToMany(Location::class, 'inventory_item_location')
                ->withPivot('quantity', 'is_active')
                ->withTimestamps()
        );
    }
}
