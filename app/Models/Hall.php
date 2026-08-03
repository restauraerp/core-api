<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Hall extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'location_id',
        'name',
        'capacity',
        'price',
    ];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}
