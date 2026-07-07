<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hall extends Model
{
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
