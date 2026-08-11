<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TracksUploadedAssets;

class ProductMedia extends Model
{
    use BelongsToTenant;
    use TracksUploadedAssets;

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

    /**
     * @return list<string>
     */
    public function uploadedAssetColumns(): array
    {
        return ['url'];
    }
}
