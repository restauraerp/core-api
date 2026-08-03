<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Organization extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name'];
}
