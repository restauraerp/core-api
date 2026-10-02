<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A restaurant website's tags and conversion secrets - see the create_site_integrations migration. */
class SiteIntegration extends Model
{
    use BelongsToTenant;

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

    /** The ids the browser loads tags for. */
    public function publicTags(): array
    {
        return [
            'gtm_id' => $this->gtm_id,
            'ga4_id' => $this->ga4_id,
            'meta_pixel_id' => $this->meta_pixel_id,
            'clarity_id' => $this->clarity_id,
            // The server sends GA4 purchases itself; the browser must not too,
            // or GA4 counts each one twice. (Meta pairs its two by event id.)
            'ga4_server' => $this->sendsGa4(),
        ];
    }

    public function sendsMeta(): bool
    {
        return (bool) ($this->meta_pixel_id && $this->meta_capi_token);
    }

    public function sendsGa4(): bool
    {
        return (bool) ($this->ga4_id && $this->ga4_api_secret);
    }
}
