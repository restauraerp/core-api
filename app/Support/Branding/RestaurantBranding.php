<?php

namespace App\Support\Branding;

use App\Models\WebsiteSetting;

/**
 * What a restaurant is called, on anything a customer sees.
 *
 * The keys are the ones the Flutter POS already reads to head its printed
 * receipt (`VenueDetails::fromSettings`), so a restaurant that has set its name
 * once has set it for the till, the printed slip and the shared invoice alike.
 * That mattered enough to reuse: the web receipt had "RESTORA ERP", "123
 * Restaurant Street" and a placeholder phone number hardcoded into it, and was
 * handing that to real customers.
 *
 * `website_settings` is a key/value table per tenant, so this reads one row per
 * key in a single query rather than letting each caller guess at the names.
 */
class RestaurantBranding
{
    /** Fallbacks are deliberately generic - never another restaurant's name. */
    private const DEFAULTS = [
        'name' => 'Restaurant',
        'address' => null,
        'phone' => null,
        'email' => null,
        'currency' => '৳',
        'logo_url' => null,
    ];

    /**
     * Keyed by what the receipt calls it, valued by the settings key it lives
     * under.
     */
    private const KEYS = [
        'name' => 'site_name',
        'address' => 'address',
        'phone' => 'contact_phone',
        'email' => 'contact_email',
        'currency' => 'currency_symbol',
        'logo_url' => 'logo_url',
    ];

    /**
     * @return array<string, string|null>
     */
    public static function current(): array
    {
        $stored = WebsiteSetting::query()
            ->whereIn('key', array_values(self::KEYS))
            ->pluck('value', 'key');

        $branding = [];

        foreach (self::KEYS as $field => $key) {
            $value = trim((string) $stored->get($key));

            $branding[$field] = $value === '' ? self::DEFAULTS[$field] : $value;
        }

        return $branding;
    }
}
