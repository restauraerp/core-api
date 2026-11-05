<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A page of the restaurant's website as the editor has it - a draft. Visitors
 * see the live SiteVersion, which Publish website copies these into.
 */
class SitePage extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'slug',
        'kind',
        'title',
        'meta_title',
        'meta_description',
        'og_image',
        'is_home',
        'sort_order',
        'draft',
    ];

    protected function casts(): array
    {
        return [
            'is_home' => 'boolean',
            'sort_order' => 'integer',
            'draft' => 'array',
        ];
    }
}
