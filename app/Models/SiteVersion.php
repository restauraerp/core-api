<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One published version of a restaurant's website - every page, the theme,
 * the header and footer, as they were when Publish website was pressed. See
 * the create_site_versions migration.
 */
class SiteVersion extends Model
{
    use BelongsToTenant;

    /** website_settings key holding the id of the version visitors see. */
    public const LIVE_KEY = 'site_live_version';

    protected $fillable = ['number', 'snapshot', 'note', 'published_by', 'published_at'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'snapshot' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** The version visitors see, or null for a site never published. */
    public static function live(): ?self
    {
        $id = WebsiteSetting::where('key', self::LIVE_KEY)->value('value');

        return $id ? self::find((int) $id) : null;
    }

    public function makeLive(): void
    {
        WebsiteSetting::updateOrCreate(['key' => self::LIVE_KEY], ['value' => (string) $this->getKey(), 'type' => 'string']);
    }
}
