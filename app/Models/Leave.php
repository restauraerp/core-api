<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Leave extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'start_date',
        'end_date',
        'reason',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
