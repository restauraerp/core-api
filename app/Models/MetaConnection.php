<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A restaurant's connected Facebook Page and Instagram account - see the create_meta_connections migration. */
class MetaConnection extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_token', 'user_token_expires_at', 'user_name', 'page_id', 'page_name', 'page_token',
        'instagram_id', 'instagram_username', 'synced_at', 'last_error',
    ];

    protected $hidden = ['user_token', 'page_token'];

    /** Where the website's Follow buttons go. */
    public static function accountLinks(): array
    {
        $meta = self::first();

        return [
            'facebook' => $meta?->page_id ? ['name' => $meta->page_name, 'url' => "https://www.facebook.com/{$meta->page_id}"] : null,
            'instagram' => $meta?->instagram_username ? ['name' => '@'.$meta->instagram_username, 'url' => "https://www.instagram.com/{$meta->instagram_username}/"] : null,
        ];
    }

    protected function casts(): array
    {
        return [
            'user_token' => 'encrypted',
            'page_token' => 'encrypted',
            'user_token_expires_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
