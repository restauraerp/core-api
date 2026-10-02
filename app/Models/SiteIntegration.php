<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A restaurant website's tags and conversion secrets - see the
 * create_site_integrations migration.
 *
 * The tag ids here are the editor's draft. What visitors load, and what
 * server-side conversions report to, are the ids in the live SiteVersion -
 * a new pixel goes live with Publish website, like everything else on the
 * site. The secrets are not versioned: they never reach a browser, and a
 * version's snapshot is not where a token should be copied to.
 */
class SiteIntegration extends Model
{
    use BelongsToTenant;

    public const TAG_KEYS = ['gtm_id', 'ga4_id', 'meta_pixel_id', 'clarity_id'];

    /** Each id's own format, checked on save and again whenever one is read back from a version. */
    public const TAG_FORMATS = [
        'gtm_id' => '/^GTM-[A-Z0-9]{4,12}$/',
        'ga4_id' => '/^G-[A-Z0-9]{4,14}$/',
        'meta_pixel_id' => '/^\d{10,20}$/',
        'clarity_id' => '/^[a-z0-9]{6,20}$/',
    ];

    protected $fillable = [
        'gtm_id', 'ga4_id', 'meta_pixel_id', 'clarity_id',
        'meta_capi_token', 'meta_test_event_code', 'ga4_api_secret',
    ];

    protected $hidden = ['meta_capi_token', 'ga4_api_secret'];

    protected function casts(): array
    {
        return [
            'meta_capi_token' => 'encrypted',
            'ga4_api_secret' => 'encrypted',
        ];
    }

    /** The current restaurant's, or null when it has set none. */
    public static function current(): ?self
    {
        return self::query()->first();
    }

    /**
     * Tag ids with every key present, anything malformed dropped.
     *
     * @return array{gtm_id: ?string, ga4_id: ?string, meta_pixel_id: ?string, clarity_id: ?string}
     */
    public static function tagsFrom(?array $tags): array
    {
        $out = [];
        foreach (self::TAG_FORMATS as $key => $format) {
            $value = $tags[$key] ?? null;
            $out[$key] = is_string($value) && preg_match($format, $value) ? $value : null;
        }

        return $out;
    }

    /** The draft ids, as Publish website copies them into a version. */
    public static function draftTags(): array
    {
        return self::tagsFrom(self::current()?->only(self::TAG_KEYS));
    }

    /** The ids visitors load: the live version's. None before the site is first published with some. */
    public static function liveTags(): array
    {
        return self::tagsFrom(SiteVersion::live()?->snapshot['tracking'] ?? null);
    }

    /** What the website and ordering load, or null when no tag is live. */
    public static function publicTags(): ?array
    {
        $live = self::liveTags();
        if (array_filter($live) === []) {
            return null;
        }

        // The server sends GA4 purchases itself; the browser must not too,
        // or GA4 counts each one twice. (Meta pairs its two by event id.)
        return $live + ['ga4_server' => (bool) self::current()?->sendsGa4()];
    }

    /** Server-side conversions go to the live pixel, with the stored token. */
    public function sendsMeta(): bool
    {
        return (bool) (self::liveTags()['meta_pixel_id'] && $this->meta_capi_token);
    }

    public function sendsGa4(): bool
    {
        return (bool) (self::liveTags()['ga4_id'] && $this->ga4_api_secret);
    }

    public function livePixelId(): ?string
    {
        return self::liveTags()['meta_pixel_id'];
    }

    public function liveGa4Id(): ?string
    {
        return self::liveTags()['ga4_id'];
    }
}
