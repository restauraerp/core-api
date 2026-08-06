<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AccountingLedger extends Model
{
    use BelongsToTenant;

    /**
     * tenant_id is deliberately absent - BelongsToTenant stamps it, and
     * listing it here would let a request body move this row to another
     * restaurant.
     */
    protected $fillable = [
        'location_id',
        'transaction_type',
        'amount',
        'reference_id',
        'description',
    ];
}
