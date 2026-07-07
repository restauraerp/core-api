<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Table extends Model
{
    protected $fillable = [
        'location_id',
        'name',
        'capacity',
    ];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
