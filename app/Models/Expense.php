<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Expense extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function loggedBy()
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
