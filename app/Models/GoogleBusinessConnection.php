<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A restaurant's connected Google Business Profile - see the create_google_business_connections migration. */
class GoogleBusinessConnection extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'refresh_token', 'access_token', 'access_token_expires_at', 'google_email',
        'location', 'location_title', 'maps_uri', 'review_uri',
        'average_rating', 'total_reviews', 'synced_at', 'last_error',
    ];

    protected $hidden = ['refresh_token', 'access_token'];

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'average_rating' => 'float',
            'total_reviews' => 'integer',
            'synced_at' => 'datetime',
        ];
    }
}
