<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TracksUploadedAssets;

class ProductCategory extends Model
{
    use BelongsToTenant;
    use TracksUploadedAssets;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'image_url',
        'is_active',
    ];

    public function parent()
    {
        return $this->belongsTo(ProductCategory::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(ProductCategory::class, 'parent_id');
    }

    public function image()
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    /**
     * @return list<string>
     */
    public function uploadedAssetColumns(): array
    {
        return ['image_url'];
    }
}
