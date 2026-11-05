<?php

namespace App\Support\Site;

use Illuminate\Validation\Rule;

/**
 * A website's site-wide look and frame: colours, fonts, header and footer.
 *
 * Colours are five values - primary, secondary, accent, neutral (footer and
 * banner) and base (the page) - and the front works out every other shade and
 * a readable text colour on each. `palette` names the catalogue entry they
 * came from, or "custom"; it is a label, the colours are what count.
 *
 * Fonts are a heading and a body family from config/site_fonts.php.
 *
 * Older themes stored a palette name only and a single "font" style; from()
 * reads those too, so a published version from before still renders the same.
 */
final class SiteTheme
{
    public const COLOR_KEYS = ['primary', 'secondary', 'accent', 'neutral', 'base'];

    /** The type styles before font pairs, as heading and body. */
    private const LEGACY_FONTS = [
        'modern' => ['Inter', 'Inter'],
        'rounded' => ['Poppins', 'Poppins'],
        'classic' => ['Lora', 'Lora'],
        'bangla' => ['Hind Siliguri', 'Hind Siliguri'],
    ];

    /** @return array<string, array{category: string, weights: string, bangla: bool}> */
    public static function fonts(): array
    {
        return config('site_fonts');
    }

    /** Validation rules for a theme update; every key optional. */
    public static function rules(): array
    {
        $fonts = array_keys(self::fonts());

        return [
            'palette' => ['sometimes', 'string', 'regex:/^[a-z0-9-]{1,40}$/'],
            'colors' => ['sometimes', 'nullable', 'array:'.implode(',', self::COLOR_KEYS)],
            'colors.*' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'heading_font' => ['sometimes', 'string', Rule::in($fonts)],
            'body_font' => ['sometimes', 'string', Rule::in($fonts)],
            // The old single style, still accepted.
            'font' => ['sometimes', 'string', Rule::in(array_keys(self::LEGACY_FONTS))],
            'header_order_button' => ['sometimes', 'boolean'],
            'footer_social' => ['sometimes', 'boolean'],
            'footer_text' => ['sometimes', 'nullable', 'string', 'max:200', 'not_regex:/<[^>]*>/'],
        ];
    }

    /**
     * A theme with every key present and valid.
     *
     * @return array{palette: string, colors: array<string, string>|null, heading_font: string, body_font: string, header_order_button: bool, footer_social: bool, footer_text: string}
     */
    public static function from(array $theme): array
    {
        $fonts = self::fonts();
        [$legacyHeading, $legacyBody] = self::LEGACY_FONTS[$theme['font'] ?? ''] ?? self::LEGACY_FONTS['modern'];
        $font = fn (?string $name, string $fallback) => $name !== null && isset($fonts[$name]) ? $name : $fallback;

        $colors = is_array($theme['colors'] ?? null) ? $theme['colors'] : null;
        if ($colors !== null) {
            $colors = array_intersect_key($colors, array_flip(self::COLOR_KEYS));
            $complete = count($colors) === count(self::COLOR_KEYS)
                && array_filter($colors, fn ($c) => ! is_string($c) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $c)) === [];
            $colors = $complete ? array_map('strtoupper', array_merge(array_flip(self::COLOR_KEYS), $colors)) : null;
        }

        $palette = $theme['palette'] ?? null;

        return [
            'palette' => is_string($palette) && preg_match('/^[a-z0-9-]{1,40}$/', $palette) ? $palette : 'emerald',
            // Null: the front takes the colours of the named catalogue palette.
            'colors' => $colors,
            'heading_font' => $font($theme['heading_font'] ?? null, $legacyHeading),
            'body_font' => $font($theme['body_font'] ?? null, $legacyBody),
            'header_order_button' => (bool) ($theme['header_order_button'] ?? true),
            'footer_social' => (bool) ($theme['footer_social'] ?? true),
            'footer_text' => (string) ($theme['footer_text'] ?? ''),
        ];
    }

    /**
     * What the front needs to load and fall back for a theme's two fonts.
     *
     * @return array{heading: array{name: string, category: string, weights: string}, body: array{name: string, category: string, weights: string}}
     */
    public static function fontFaces(array $theme): array
    {
        $fonts = self::fonts();
        $face = fn (string $name) => ['name' => $name, 'category' => $fonts[$name]['category'] ?? 'sans-serif', 'weights' => $fonts[$name]['weights'] ?? '400'];

        return ['heading' => $face($theme['heading_font']), 'body' => $face($theme['body_font'])];
    }
}
