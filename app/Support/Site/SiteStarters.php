<?php

namespace App\Support\Site;

use App\Models\Tenant;

/**
 * Starter websites: whole sites an owner can begin from - colours, fonts,
 * which pages, and how the home page is laid out - each filled with the
 * restaurant's own menu, photos, branches and reviews by SiteDraft.
 *
 * Everything a starter produces is ordinary pages and an ordinary theme,
 * checked like any other, so the owner changes what they like after.
 * Colours are a palette name from the front's catalogue (lib/site/palettes).
 */
final class SiteStarters
{
    /** @return array<string, array{name: string, description: string, tags: list<string>, palette: string, heading_font: string, body_font: string, pages: list<string>, home: string, headings: array<string, string>}> */
    public static function all(): array
    {
        return [
            'classic' => [
                'name' => 'Classic restaurant',
                'description' => 'Warm and welcoming, every page a restaurant needs.',
                'tags' => ['family', 'bangladeshi', 'multi-cuisine'],
                'palette' => 'saffron-classic', 'heading_font' => 'Playfair Display', 'body_font' => 'Lato',
                'pages' => SiteDraft::KINDS, 'home' => 'story',
                'headings' => ['featured' => 'Our favourites'],
            ],
            'biryani_house' => [
                'name' => 'Kacchi & biryani house',
                'description' => 'Rich colours and Bangla-friendly type for a biryani or Mughlai kitchen.',
                'tags' => ['biryani', 'kacchi', 'mughlai', 'bangla'],
                'palette' => 'turmeric-classic', 'heading_font' => 'Playfair Display', 'body_font' => 'Hind Siliguri',
                'pages' => ['home', 'menu', 'order', 'reservations', 'about', 'gallery', 'locations', 'contact'], 'home' => 'order_first',
                'headings' => ['featured' => 'From our handi', 'order' => 'Hot biryani, delivered'],
            ],
            'cafe' => [
                'name' => 'Coffee café',
                'description' => 'Cosy browns, a story with a photo and a light menu list.',
                'tags' => ['coffee', 'cafe', 'tea', 'brunch'],
                'palette' => 'espresso-classic', 'heading_font' => 'Fraunces', 'body_font' => 'Work Sans',
                'pages' => ['home', 'menu', 'order', 'about', 'gallery', 'contact'], 'home' => 'gallery',
                'headings' => ['featured' => 'Guest favourites'],
            ],
            'fast_food' => [
                'name' => 'Burgers & fast food',
                'description' => 'Bold and loud, ordering first.',
                'tags' => ['burger', 'fried chicken', 'fast food', 'takeaway'],
                'palette' => 'chili-fresh', 'heading_font' => 'Bebas Neue', 'body_font' => 'Montserrat',
                'pages' => ['home', 'menu', 'order', 'locations', 'contact'], 'home' => 'order_first',
                'headings' => ['featured' => 'Best sellers', 'order' => 'Hungry? Order in a minute'],
            ],
            'pizza' => [
                'name' => 'Pizzeria',
                'description' => 'Tomato red and a slab-serif headline, menu up front.',
                'tags' => ['pizza', 'pasta', 'italian'],
                'palette' => 'tomato-classic', 'heading_font' => 'Alfa Slab One', 'body_font' => 'Roboto',
                'pages' => ['home', 'menu', 'order', 'gallery', 'locations', 'contact'], 'home' => 'menu_first',
                'headings' => ['featured' => 'Out of the oven'],
            ],
            'fine_dining' => [
                'name' => 'Fine dining',
                'description' => 'Dark and gold, elegant type, table booking to the fore.',
                'tags' => ['fine dining', 'steak', 'wine', 'elegant'],
                'palette' => 'gold-night', 'heading_font' => 'Cormorant Garamond', 'body_font' => 'Montserrat',
                'pages' => ['home', 'menu', 'reservations', 'about', 'gallery', 'contact'], 'home' => 'elegant',
                'headings' => ['featured' => 'Signature dishes'],
            ],
            'bakery' => [
                'name' => 'Bakery & sweets',
                'description' => 'Soft caramel tones and a script headline for cakes and mishti.',
                'tags' => ['bakery', 'cake', 'mishti', 'dessert', 'sweets'],
                'palette' => 'caramel-fresh', 'heading_font' => 'Satisfy', 'body_font' => 'Raleway',
                'pages' => ['home', 'menu', 'order', 'gallery', 'locations', 'contact'], 'home' => 'gallery',
                'headings' => ['featured' => 'Freshly baked'],
            ],
            'chinese_thai' => [
                'name' => 'Chinese & Thai',
                'description' => 'Deep red and classic lettering for an Asian kitchen.',
                'tags' => ['chinese', 'thai', 'asian', 'noodles'],
                'palette' => 'crimson-classic', 'heading_font' => 'Marcellus', 'body_font' => 'Montserrat',
                'pages' => SiteDraft::KINDS, 'home' => 'story',
                'headings' => ['featured' => 'Chef recommends'],
            ],
            'street_food' => [
                'name' => 'Street food',
                'description' => 'Bright mango and a hand-lettered feel - fuchka, chotpoti, rolls.',
                'tags' => ['street food', 'fuchka', 'snacks', 'food truck'],
                'palette' => 'mango-fresh', 'heading_font' => 'Kaushan Script', 'body_font' => 'Montserrat',
                'pages' => ['home', 'menu', 'order', 'gallery', 'locations'], 'home' => 'order_first',
                'headings' => ['featured' => 'Crowd favourites'],
            ],
            'healthy' => [
                'name' => 'Healthy & salads',
                'description' => 'Fresh greens, clean type and lots of white space.',
                'tags' => ['healthy', 'salad', 'juice', 'vegan'],
                'palette' => 'basil-fresh', 'heading_font' => 'Outfit', 'body_font' => 'DM Sans',
                'pages' => ['home', 'menu', 'order', 'about', 'locations', 'contact'], 'home' => 'menu_first',
                'headings' => ['featured' => 'Fresh today'],
            ],
            'steakhouse' => [
                'name' => 'Grill & steakhouse',
                'description' => 'Smoky dark theme with strong headlines for a grill or BBQ.',
                'tags' => ['grill', 'bbq', 'steak', 'kebab'],
                'palette' => 'paprika-night', 'heading_font' => 'Oswald', 'body_font' => 'Lato',
                'pages' => ['home', 'menu', 'order', 'reservations', 'gallery', 'locations', 'contact'], 'home' => 'elegant',
                'headings' => ['featured' => 'Off the grill'],
            ],
            'cloud_kitchen' => [
                'name' => 'Cloud kitchen',
                'description' => 'Delivery only: no dining room pages, ordering everywhere.',
                'tags' => ['delivery', 'cloud kitchen', 'online only'],
                'palette' => 'teal-fresh', 'heading_font' => 'Space Grotesk', 'body_font' => 'Inter',
                'pages' => ['home', 'menu', 'order', 'contact'], 'home' => 'order_first',
                'headings' => ['featured' => 'Most ordered', 'order' => 'Order now, delivered hot'],
            ],
        ];
    }

    /**
     * A starter's theme and pages for this restaurant.
     *
     * @return array{theme: array<string, mixed>, pages: list<array<string, mixed>>}
     */
    public static function build(string $key, Tenant $tenant, bool $withPhoto = true): array
    {
        $starter = self::all()[$key] ?? throw new \InvalidArgumentException("Unknown starter {$key}");
        $draft = new SiteDraft($tenant);
        $modules = $tenant->modules();

        // Pages the restaurant can have (no Book a table without CRM).
        $pages = array_values(array_filter(array_map(fn (string $kind) => $draft->page($kind, $withPhoto), $starter['pages'])));
        $slugs = array_column($pages, 'slug');

        foreach ($pages as $i => $page) {
            if ($page['kind'] === 'home') {
                $home = array_filter(
                    self::home($starter, $page['sections'], $draft->page('gallery', $withPhoto)),
                    // A button to a page this site does not have would only lead home.
                    fn (array $s) => $s['type'] !== 'button' || ($s['props']['target'] ?? '') !== 'page' || in_array($s['props']['page'] ?? '', $slugs, true),
                );
                $pages[$i]['sections'] = SiteSections::clean(array_values($home), $modules);
            }
        }

        return [
            'theme' => SiteTheme::from([
                'palette' => $starter['palette'],
                'heading_font' => $starter['heading_font'],
                'body_font' => $starter['body_font'],
            ]),
            'pages' => $pages,
        ];
    }

    /**
     * The home page in the starter's layout, from the drafted home's own
     * sections (so its words and photos are the restaurant's).
     *
     * @param  list<array<string, mixed>>  $drafted
     * @return list<array<string, mixed>>
     */
    private static function home(array $starter, array $drafted, ?array $gallery): array
    {
        $by = collect($drafted)->groupBy('type');
        $one = fn (string $type) => $by->get($type)?->first();
        $hero = $one('hero');
        $welcome = $one('text');
        $featured = $one('featured');
        $reviews = $one('reviews');
        $order = $one('order_button');
        $photos = $gallery['sections'][0]['props']['images'] ?? [];

        $with = fn (?array $section, array $props) => $section ? ['type' => $section['type'], 'props' => array_merge($section['props'], $props)] : null;
        $featuredHeading = ['heading' => $starter['headings']['featured'] ?? 'Our favourites'];
        $orderHeading = isset($starter['headings']['order']) ? ['heading' => $starter['headings']['order']] : [];

        $sections = match ($starter['home']) {
            'order_first' => [
                $with($hero, ['align' => 'left', 'button' => 'order']),
                $with($order, $orderHeading + ['background' => 'brand']),
                $with($featured, $featuredHeading + ['count' => '6']),
                ['type' => 'button', 'props' => ['label' => 'See the full menu', 'target' => 'page', 'page' => 'menu']],
                $with($reviews, ['background' => 'muted']),
            ],
            'menu_first' => [
                $with($hero, ['button' => 'menu', 'button_label' => 'See the menu']),
                ['type' => 'menu', 'props' => ['heading' => 'Our menu', 'layout' => 'list', 'show_images' => false]],
                $with($order, $orderHeading),
                $with($reviews, []),
            ],
            'elegant' => [
                $with($hero, ['align' => 'left', 'button' => 'none']),
                $welcome ? ['type' => 'text', 'props' => array_merge($welcome['props'], ['heading' => 'Welcome', 'align' => 'center', 'background' => 'muted'])] : null,
                $with($featured, $featuredHeading),
                ['type' => 'button', 'props' => ['label' => 'Book a table', 'target' => 'page', 'page' => 'reservations']],
                $with($reviews, []),
            ],
            'gallery' => [
                $with($hero, []),
                $with($welcome, []),
                $with($featured, $featuredHeading + ['count' => '6']),
                $photos ? ['type' => 'gallery', 'props' => ['heading' => '', 'images' => array_slice($photos, 0, 8), 'columns' => '4']] : null,
                $with($order, $orderHeading),
            ],
            default => [
                $with($hero, []),
                $with($welcome, []),
                $with($featured, $featuredHeading),
                ['type' => 'button', 'props' => ['label' => 'See the full menu', 'target' => 'page', 'page' => 'menu']],
                $with($order, $orderHeading),
                $with($reviews, []),
            ],
        };

        return array_values(array_filter($sections));
    }
}
