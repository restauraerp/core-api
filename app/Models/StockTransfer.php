<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    use BelongsToTenant;

    /**
     * tenant_id is deliberately absent - BelongsToTenant stamps it, and
     * listing it here would let a request body move this row to another
     * restaurant.
     */
    protected $fillable = [
        'inventory_item_id',
        'from_storage_id',
        'to_storage_id',
        'quantity',
        'transferred_by',
    ];
}
