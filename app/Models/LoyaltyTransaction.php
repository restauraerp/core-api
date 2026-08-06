<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class LoyaltyTransaction extends Model
{
    use BelongsToTenant;

    /**
     * tenant_id is deliberately absent - BelongsToTenant stamps it, and
     * listing it here would let a request body move this row to another
     * restaurant.
     */
    protected $fillable = [
        'customer_id',
        'order_id',
        'points',
        'type',
        'description',
    ];
}
