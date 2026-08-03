<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class StorageUnit extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'location_id',
        'name',
        'type',
    ];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}
