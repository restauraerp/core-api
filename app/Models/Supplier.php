<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use BelongsToTenant;

    /**
     * tenant_id is deliberately absent - BelongsToTenant stamps it, and listing
     * it here would let a request body reassign a supplier to another
     * restaurant.
     */
    protected $fillable = [
        'name',
        'contact_name',
        'phone',
        'email',
        'address',
    ];
}
