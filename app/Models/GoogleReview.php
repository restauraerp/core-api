<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class GoogleReview extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'author_name',
        'rating',
        'text',
        'time',
        'is_displayed',
        'source',
        'external_id',
        'author_photo_url',
    ];

    protected $casts = [
        'time' => 'datetime',
        'is_displayed' => 'boolean',
        'rating' => 'integer',
    ];

    public $timestamps = false;
}
