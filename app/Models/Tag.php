<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Tag extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'name',
        'slug',
    ];

    public $timestamps = false;

    public function products()
    {
        return $this->scopedToTenantPivot(
            $this->belongsToMany(Product::class)
        );
    }
}
