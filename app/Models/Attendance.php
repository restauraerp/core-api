<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Attendance extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'date',
        'check_in',
        'check_out',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
