<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Image extends Model
{
    use BelongsToTenant;

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
}
