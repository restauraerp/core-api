<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class SocialLink extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'platform',
        'url',
        'is_active',
    ];

    public $timestamps = false;
}
