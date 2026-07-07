<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleReview extends Model
{
    protected $fillable = [
        'author_name',
        'rating',
        'text',
        'time',
        'is_displayed',
    ];

    public $timestamps = false;
}
