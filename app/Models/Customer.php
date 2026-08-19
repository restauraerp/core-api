<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\NormalisesPhone;

class Customer extends Model
{
    use BelongsToTenant, NormalisesPhone;

    protected $fillable = ['name', 'phone', 'email', 'address', 'loyalty_points', 'tier', 'organization_id', 'google_map_location'];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
