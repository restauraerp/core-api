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
 *
 * `category` groups the picker (restaurants, hotels & resorts, events), and
 * `needs` names the modules a starter is built around - a hotel starter on a
 * restaurant without the Hotel add-on still works, it simply has no rooms, and
 * the picker says so rather than leaving the owner to wonder where they went.
 */
final class SiteStarters
{
    public const CATEGORIES = ['restaurant' => 'Restaurants', 'stay' => 'Hotels & resorts', 'events' => 'Events & banquets'];

    /** @return array<string, array{name: string, description: string, category: string, needs: list<string>, tags: list<string>, palette: string, heading_font: string, body_font: string, pages: list<string>, home: string, headings: array<string, string>, subtitle?: string}> */
    public static function all(): array
    {
        return array_map(
            fn (array $starter) => $starter + ['category' => 'restaurant', 'needs' => []],
            self::restaurants() + self::stays(),
        );
    }

    /** @return array<string, array<string, mixed>> */
    private static function restaurants(): array
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
            'bangla_home' => [
                'name' => 'Bangla home cooking',
                'description' => 'Bhorta, bhaat and maacher jhol - homely turmeric tones and Bangla type.',
                'tags' => ['bangla', 'bhorta', 'bhaat', 'fish curry', 'home style', 'deshi'],
                'palette' => 'turmeric-fresh', 'heading_font' => 'Noto Serif Bengali', 'body_font' => 'Hind Siliguri',
                'pages' => ['home', 'menu', 'order', 'about', 'locations', 'contact'], 'home' => 'menu_first',
                'headings' => ['featured' => 'Ma-er haater ranna'],
            ],
            'buffet' => [
                'name' => 'Buffet restaurant',
                'description' => 'Generous and bright, for an all-you-can-eat lunch and dinner buffet.',
                'tags' => ['buffet', 'all you can eat', 'family', 'iftar', 'lunch'],
                'palette' => 'saffron-fresh', 'heading_font' => 'Prata', 'body_font' => 'Poppins',
                'pages' => ['home', 'menu', 'reservations', 'events', 'gallery', 'locations', 'contact'], 'home' => 'gallery',
                'headings' => ['featured' => 'On the buffet today'],
            ],
            'rooftop' => [
                'name' => 'Rooftop restaurant & lounge',
                'description' => 'Night-sky indigo for a rooftop with a view - booking and events first.',
                'tags' => ['rooftop', 'lounge', 'view', 'mocktail', 'night', 'shisha'],
                'palette' => 'indigo-night', 'heading_font' => 'Josefin Sans', 'body_font' => 'Poppins',
                'pages' => ['home', 'menu', 'reservations', 'events', 'gallery', 'contact'], 'home' => 'elegant',
                'headings' => ['featured' => 'Under the stars'],
            ],
            'seafood' => [
                'name' => 'Seafood',
                'description' => 'Coastal blues and big photos - prawns, crab, rupchanda and the catch of the day.',
                'tags' => ['seafood', 'fish', 'prawn', 'crab', 'coastal', "cox's bazar"],
                'palette' => 'azure-fresh', 'heading_font' => 'Abril Fatface', 'body_font' => 'Open Sans',
                'pages' => ['home', 'menu', 'order', 'reservations', 'gallery', 'locations', 'contact'], 'home' => 'gallery',
                'headings' => ['featured' => 'Catch of the day'],
            ],
            'arabian' => [
                'name' => 'Arabian & mandi',
                'description' => 'Desert sand and classic serifs for mandi, shawarma and kunafa.',
                'tags' => ['arabian', 'mandi', 'shawarma', 'kabsa', 'turkish', 'middle eastern'],
                'palette' => 'sand-classic', 'heading_font' => 'Libre Baskerville', 'body_font' => 'Nunito',
                'pages' => ['home', 'menu', 'order', 'reservations', 'gallery', 'locations', 'contact'], 'home' => 'story',
                'headings' => ['featured' => 'From the mandi pit'],
            ],
            'indian' => [
                'name' => 'Indian & tandoor',
                'description' => 'Clay-oven terracotta for tandoori, curries and dosa.',
                'tags' => ['indian', 'tandoori', 'naan', 'dosa', 'south indian', 'curry'],
                'palette' => 'terracotta-classic', 'heading_font' => 'Yeseva One', 'body_font' => 'Mulish',
                'pages' => SiteDraft::KINDS, 'home' => 'story',
                'headings' => ['featured' => 'Fresh from the tandoor'],
            ],
            'japanese' => [
                'name' => 'Japanese & sushi',
                'description' => 'Calm slate and matcha, lots of space and the menu up front.',
                'tags' => ['japanese', 'sushi', 'ramen', 'korean', 'asian'],
                'palette' => 'slate-classic', 'heading_font' => 'Noto Serif', 'body_font' => 'Manrope',
                'pages' => ['home', 'menu', 'order', 'reservations', 'gallery', 'contact'], 'home' => 'menu_first',
                'headings' => ['featured' => 'Omakase favourites'],
            ],
            'dessert' => [
                'name' => 'Ice cream & desserts',
                'description' => 'Playful raspberry and round type for ice cream, waffles and shakes.',
                'tags' => ['ice cream', 'dessert', 'waffle', 'shake', 'gelato', 'kids'],
                'palette' => 'raspberry-fresh', 'heading_font' => 'Fredoka', 'body_font' => 'Quicksand',
                'pages' => ['home', 'menu', 'order', 'gallery', 'locations'], 'home' => 'gallery',
                'headings' => ['featured' => 'Scoop of the day'],
            ],
            'tea_house' => [
                'name' => 'Tea house & adda',
                'description' => 'Cinnamon warmth and hand-drawn type for cha, singara and long conversations.',
                'tags' => ['tea', 'cha', 'adda', 'snacks', 'tong', 'singara'],
                'palette' => 'cinnamon-classic', 'heading_font' => 'Amatic SC', 'body_font' => 'Karla',
                'pages' => ['home', 'menu', 'order', 'about', 'locations'], 'home' => 'menu_first',
                'headings' => ['featured' => 'With your cha'],
            ],
        ];
    }

    /**
     * Places to stay, and places to celebrate: homes built around the Rooms
     * and Venues sections rather than the menu.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function stays(): array
    {
        return [
            'hotel' => [
                'name' => 'City hotel',
                'description' => 'Rooms first with online booking, then the restaurant, events and how to find you.',
                'category' => 'stay', 'needs' => ['hotel'],
                'tags' => ['hotel', 'rooms', 'business', 'city', 'stay'],
                'palette' => 'ocean-classic', 'heading_font' => 'DM Serif Display', 'body_font' => 'Inter',
                'pages' => ['home', 'rooms', 'menu', 'reservations', 'events', 'gallery', 'locations', 'contact'], 'home' => 'stay',
                'headings' => ['featured' => 'At our restaurant', 'rooms' => 'Our rooms'],
                'subtitle' => 'Comfortable rooms, good food and a warm welcome.',
            ],
            'resort' => [
                'name' => 'Resort & spa',
                'description' => 'Big photos and calm lagoon colours - the stay, the dining and the events lawn.',
                'category' => 'stay', 'needs' => ['hotel'],
                'tags' => ['resort', 'spa', 'pool', 'holiday', 'weekend', 'getaway'],
                'palette' => 'lagoon-fresh', 'heading_font' => 'Cormorant Garamond', 'body_font' => 'Nunito Sans',
                'pages' => ['home', 'rooms', 'events', 'menu', 'gallery', 'about', 'contact'], 'home' => 'resort',
                'headings' => ['featured' => 'Dining at the resort', 'rooms' => 'Stay with us'],
                'subtitle' => 'Slow mornings, long lunches and nights under the stars.',
            ],
            'beach_resort' => [
                'name' => 'Beach resort',
                'description' => 'Sunset coral for a seaside stay - sea-view rooms and fresh seafood.',
                'category' => 'stay', 'needs' => ['hotel'],
                'tags' => ['beach', 'sea view', "cox's bazar", 'kuakata', 'resort', 'holiday'],
                'palette' => 'coral-fresh', 'heading_font' => 'Yeseva One', 'body_font' => 'Mulish',
                'pages' => ['home', 'rooms', 'menu', 'gallery', 'locations', 'contact'], 'home' => 'resort',
                'headings' => ['featured' => 'Fresh from the sea', 'rooms' => 'Rooms by the sea'],
                'subtitle' => 'Wake up to the sound of the waves.',
            ],
            'eco_resort' => [
                'name' => 'Eco resort & cottages',
                'description' => 'Tea-garden greens for cottages in the hills - Sreemangal, Sylhet, Bandarban.',
                'category' => 'stay', 'needs' => ['hotel'],
                'tags' => ['eco', 'cottage', 'tea garden', 'hill', 'nature', 'sreemangal', 'bandarban'],
                'palette' => 'matcha-fresh', 'heading_font' => 'Fraunces', 'body_font' => 'Figtree',
                'pages' => ['home', 'rooms', 'menu', 'gallery', 'about', 'contact'], 'home' => 'resort',
                'headings' => ['featured' => 'Farm to table', 'rooms' => 'Our cottages'],
                'subtitle' => 'Cottages among the green, home-cooked meals and quiet.',
            ],
            'boutique_hotel' => [
                'name' => 'Boutique hotel',
                'description' => 'Deep aubergine and a fashion serif - a handful of beautiful rooms and a fine restaurant.',
                'category' => 'stay', 'needs' => ['hotel'],
                'tags' => ['boutique', 'luxury', 'design hotel', 'couples', 'elegant'],
                'palette' => 'aubergine-night', 'heading_font' => 'Bodoni Moda', 'body_font' => 'Manrope',
                'pages' => ['home', 'rooms', 'menu', 'reservations', 'gallery', 'about', 'contact'], 'home' => 'stay',
                'headings' => ['featured' => 'From our kitchen', 'rooms' => 'The rooms'],
                'subtitle' => 'A small hotel with a big heart.',
            ],
            'guest_house' => [
                'name' => 'Guest house & B&B',
                'description' => 'Simple and friendly: a few rooms, breakfast and directions.',
                'category' => 'stay', 'needs' => ['hotel'],
                'tags' => ['guest house', 'bed and breakfast', 'b&b', 'budget', 'homestay', 'motel'],
                'palette' => 'forest-classic', 'heading_font' => 'Lora', 'body_font' => 'Karla',
                'pages' => ['home', 'rooms', 'menu', 'about', 'locations', 'contact'], 'home' => 'stay',
                'headings' => ['featured' => 'Breakfast and more', 'rooms' => 'Rooms'],
                'subtitle' => 'A clean room, a good breakfast and a friendly face.',
            ],
            'convention_hall' => [
                'name' => 'Convention & banquet hall',
                'description' => 'Gold and grand lettering for weddings, gaye holud and corporate events - venues and enquiries first.',
                'category' => 'events', 'needs' => ['crm'],
                'tags' => ['convention', 'banquet', 'wedding', 'community centre', 'party', 'catering'],
                'palette' => 'gold-classic', 'heading_font' => 'Cinzel', 'body_font' => 'Lato',
                'pages' => ['home', 'events', 'menu', 'gallery', 'locations', 'contact'], 'home' => 'events',
                'headings' => ['featured' => 'Catering menu', 'events' => 'Our halls'],
                'subtitle' => 'Weddings, receptions and celebrations to remember.',
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
                    self::home($starter, $page['sections'], $draft->page('gallery', $withPhoto), $slugs),
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
     * @param  list<string>  $slugs  the pages this site has
     * @return list<array<string, mixed>>
     */
    private static function home(array $starter, array $drafted, ?array $gallery, array $slugs): array
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

        // Rooms and venues only where the site has the page for them - which
        // SiteDraft gives only to a restaurant with the module and something
        // to show (room types, halls). Otherwise the section would be empty.
        $rooms = in_array('rooms', $slugs, true)
            ? ['type' => 'rooms', 'props' => ['heading' => $starter['headings']['rooms'] ?? 'Stay with us']] : null;
        $venues = in_array('events', $slugs, true)
            ? ['type' => 'venues', 'props' => ['heading' => $starter['headings']['events'] ?? 'Host your event with us']] : null;
        // A hotel's banner leads to the rooms (or a hall's to its venues); a
        // place without them falls back to the menu.
        $stayHero = fn (string $page, string $label) => $with($hero, array_filter([
            'subtitle' => $starter['subtitle'] ?? null,
            'align' => 'left',
        ]) + (in_array($page, $slugs, true) ? ['button' => $page, 'button_label' => $label] : ['button' => 'menu', 'button_label' => 'See the menu']));
        $photoStrip = $photos ? ['type' => 'gallery', 'props' => ['heading' => '', 'images' => array_slice($photos, 0, 8), 'columns' => '4']] : null;

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
            'stay' => [
                $stayHero('rooms', 'Book a room'),
                $rooms,
                $welcome ? ['type' => 'text', 'props' => array_merge($welcome['props'], ['background' => 'muted'])] : null,
                $with($featured, $featuredHeading),
                $venues ? ['type' => 'button', 'props' => ['label' => 'Plan an event with us', 'target' => 'page', 'page' => 'events']] : null,
                $with($reviews, ['background' => 'muted']),
            ],
            'resort' => [
                $stayHero('rooms', 'Check availability'),
                $welcome ? ['type' => 'text', 'props' => array_merge($welcome['props'], ['align' => 'center'])] : null,
                $photoStrip,
                $rooms ? ['type' => 'rooms', 'props' => $rooms['props'] + ['background' => 'muted']] : null,
                $with($featured, $featuredHeading),
                $venues,
                $with($reviews, ['background' => 'muted']),
            ],
            'events' => [
                $stayHero('events', 'Enquire about a date'),
                $venues,
                $welcome ? ['type' => 'text', 'props' => array_merge($welcome['props'], ['align' => 'center', 'background' => 'muted'])] : null,
                $photoStrip,
                $with($featured, $featuredHeading),
                ['type' => 'button', 'props' => ['label' => 'See the catering menu', 'target' => 'page', 'page' => 'menu']],
                $with($reviews, []),
            ],
            'gallery' => [
                $with($hero, []),
                $with($welcome, []),
                $with($featured, $featuredHeading + ['count' => '6']),
                $photoStrip,
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
