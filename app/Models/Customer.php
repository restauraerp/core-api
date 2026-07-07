<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['name', 'phone', 'email', 'address', 'loyalty_points', 'tier', 'organization_id', 'google_map_location'];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
