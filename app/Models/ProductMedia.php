<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductMedia extends Model
{
    protected $fillable = [
        'product_id',
        'media_type',
        'media_url',
        'is_primary',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
