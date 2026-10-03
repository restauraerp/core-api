<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Hall extends Model
{
    use BelongsToTenant;

    public const PER_EVENT = 'per_event';

    public const PER_HOUR = 'per_hour';

    /** A hall is also a bookable venue - see the create_venue_booking migration. */
    protected $fillable = [
        'location_id',
        'name',
        'description',
        'capacity',
        'min_guests',
        // The rent: per event, or per hour (rent_type).
        'price',
        'rent_type',
        // Kept free after each event, to clear and set up for the next.
        'buffer_minutes',
        'is_active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'min_guests' => 'integer',
        'price' => 'float',
        'buffer_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}
