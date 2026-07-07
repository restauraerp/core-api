<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageUnit extends Model
{
    protected $fillable = [
        'location_id',
        'name',
        'type',
    ];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}
