<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TracksUploadedAssets;

class Image extends Model
{
    use BelongsToTenant;
    use TracksUploadedAssets;

    protected $fillable = [
        'url',
        'imageable_id',
        'imageable_type',
        'type',
    ];

    public function imageable()
    {
        return $this->morphTo();
    }

    /**
     * @return list<string>
     */
    public function uploadedAssetColumns(): array
    {
        return ['url'];
    }
}
