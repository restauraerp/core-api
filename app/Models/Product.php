<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'price',
        'type',
        'is_active',
        'recipe_id',
    ];

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    public function locations()
    {
        return $this->belongsToMany(Location::class, 'location_product')
                    ->withPivot('is_available')
                    ->withTimestamps();
    }
}
