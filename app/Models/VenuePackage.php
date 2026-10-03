<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A per-person food menu a venue booking can include - see the create_venue_booking migration. */
class VenuePackage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'description', 'price_per_person', 'min_guests', 'is_active', 'sort_order'];

    protected $casts = [
        'price_per_person' => 'float',
        'min_guests' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
