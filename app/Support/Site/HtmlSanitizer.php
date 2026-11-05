<?php

namespace App\Support\Site;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Cleans the HTML of a custom website page (Website > Pages) before it is
 * stored.
 *
 * The storefront renders that HTML as it is, so whatever is saved runs in a
 * visitor's browser. Writing it already needs manage_website_content; this
 * is the second wall - a stolen or careless staff login still cannot plant a
 * script. Formatting survives: headings, paragraphs, lists, bold/italic,
 * links and images. Scripts, styles, frames, forms, event handlers and
 * javascript: links do not.
 */
class HtmlSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        return self::purifier()->purify($html);
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', implode(',', [
                'h2', 'h3', 'h4', 'p', 'br', 'hr', 'blockquote',
                'strong', 'b', 'em', 'i', 'u', 's', 'span',
                'ul', 'ol', 'li',
                'a[href|title|target|rel]',
                'img[src|alt|width|height]',
                'table', 'thead', 'tbody', 'tr', 'th', 'td',
            ]));
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            // New-tab links get rel=noopener noreferrer.
            $config->set('HTML.TargetNoopener', true);
            $config->set('HTML.TargetNoreferrer', true);
            // No definition cache on disk: pages are saved rarely, and a cache
            // directory is one more thing a deploy must keep writable.
            $config->set('Cache.DefinitionImpl', null);

            self::$purifier = new HTMLPurifier($config);
        }

        return self::$purifier;
    }
}
