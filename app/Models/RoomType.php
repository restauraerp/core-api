<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A kind of hotel room guests book - see the create_hotel_rooms migration. */
class RoomType extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'location_id', 'name', 'description', 'max_adults', 'max_children', 'rate', 'weekend_rate',
        'beds', 'size_sqft', 'amenities', 'photos', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'max_adults' => 'integer',
        'max_children' => 'integer',
        'rate' => 'float',
        'weekend_rate' => 'float',
        'size_sqft' => 'integer',
        'amenities' => 'array',
        'photos' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class)->orderBy('number');
    }
}
