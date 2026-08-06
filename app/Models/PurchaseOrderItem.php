<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use BelongsToTenant;

    /**
     * The model name says purchase_order_items; the table has always been
     * purchase_items. Left unset, every query hit a table that does not
     * exist.
     */
    protected $table = 'purchase_items';

    /**
     * tenant_id is deliberately absent - BelongsToTenant stamps it, and
     * listing it here would let a request body move this row to another
     * restaurant.
     */
    protected $fillable = [
        'purchase_order_id',
        'inventory_item_id',
        'quantity',
        'price',
    ];
}
