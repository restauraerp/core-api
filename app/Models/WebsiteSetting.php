<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class WebsiteSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'key',
        'value',
        'type',
    ];
}
