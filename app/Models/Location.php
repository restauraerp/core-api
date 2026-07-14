<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Enums\LocationType;

class Location extends Model
{
    protected $fillable = [
        'name',
        'slug',
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

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($location) {
            if (empty($location->slug)) {
                $location->slug = \Illuminate\Support\Str::slug($location->name);
            }
        });
        static::updating(function ($location) {
            if (empty($location->slug)) {
                $location->slug = \Illuminate\Support\Str::slug($location->name);
            }
        });
    }



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

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable')->where('type', 'image');
    }

    public function videos()
    {
        return $this->morphMany(Image::class, 'imageable')->where('type', 'video');
    }
    public function featuredImage()
    {
        return $this->morphOne(Image::class, 'imageable')->where('type', 'featured_image');
    }

    public function featuredVideo()
    {
        return $this->morphOne(Image::class, 'imageable')->where('type', 'featured_video');
    }
}
