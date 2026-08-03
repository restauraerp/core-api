<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Page extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'slug',
        'title',
        'content',
        'meta_title',
        'meta_description',
    ];
}
