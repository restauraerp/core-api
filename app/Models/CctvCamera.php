<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CctvCamera extends Model
{
    protected $fillable = [
        'location_id',
        'name',
        'stream_url',
    ];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}
