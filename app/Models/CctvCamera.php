<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class CctvCamera extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'location_id',
        'name',
        'stream_url',
    ];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}
