<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Delivery extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function rider()
    {
        return $this->belongsTo(User::class, 'rider_id');
    }
}
