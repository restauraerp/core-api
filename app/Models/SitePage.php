<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A page of the restaurant's website - see the create_site_pages migration. */
class SitePage extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'slug',
        'title',
        'is_home',
        'sort_order',
        'draft',
        'published',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_home' => 'boolean',
            'sort_order' => 'integer',
            'draft' => 'array',
            'published' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** Unpublished changes waiting in the draft. */
    public function hasUnpublishedChanges(): bool
    {
        return $this->published === null || $this->published !== $this->draft;
    }
}
