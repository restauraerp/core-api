<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use BelongsToTenant;

    /**
     * tenant_id is deliberately absent - BelongsToTenant stamps it, and
     * listing it here would let a request body move this row to another
     * restaurant.
     */
    protected $fillable = [
        'customer_id',
        'location_id',
        'hall_id',
        'table_id',
        'reservation_date',
        'guest_count',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'reservation_date' => 'datetime',
        ];
    }
}
