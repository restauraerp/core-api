<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A Facebook or Instagram post kept for the website - see the create_meta_connections migration. */
class SocialPost extends Model
{
    use BelongsToTenant;

    public const FACEBOOK = 'facebook';

    public const INSTAGRAM = 'instagram';

    protected $fillable = ['network', 'external_id', 'text', 'image_url', 'permalink', 'media_type', 'posted_at'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    /** @return list<array<string, mixed>> a network's latest posts, as the website draws them */
    public static function latest(string $network, int $limit = 12): array
    {
        return self::where('network', $network)
            ->orderByDesc('posted_at')
            ->limit($limit)
            ->get()
            ->map(fn (self $p) => [
                'text' => $p->text,
                'image' => $p->image_url,
                'url' => $p->permalink,
                'type' => $p->media_type,
                'time' => $p->posted_at?->toIso8601String(),
            ])
            ->all();
    }
}
