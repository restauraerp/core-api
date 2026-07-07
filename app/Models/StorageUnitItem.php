<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageUnitItem extends Model
{
    protected $fillable = [
        'storage_unit_id',
        'inventory_item_id',
        'quantity',
    ];

    public function storageUnit()
    {
        return $this->belongsTo(StorageUnit::class);
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
