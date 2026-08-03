<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class ProductMedia extends Model
{
    use BelongsToTenant;

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
