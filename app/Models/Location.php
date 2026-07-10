<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Enums\LocationType;

class Location extends Model
{
    protected $fillable = [
        'name',
        'type',
        'address',
        'map_url',
        'phone',
        'email',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'type' => LocationType::class,
    ];

    protected $appends = ['type_title'];

    public function getTypeTitleAttribute()
    {
        return $this->type?->title();
    }

    public function halls()
    {
        return $this->hasMany(Hall::class);
    }

    public function tables()
    {
        return $this->hasMany(Table::class);
    }

    public function cctvCameras()
    {
        return $this->hasMany(CctvCamera::class);
    }
}
