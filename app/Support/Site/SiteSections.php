<?php

namespace App\Support\Site;

use Illuminate\Validation\ValidationException;

/**
 * Every component the site builder offers, and what each may be set to.
 *
 * The single source of truth: the editor builds its settings forms from
 * definitions() (GET /site-builder/components), and clean() checks every
 * saved page against it. A section is {id, type, props}; an unknown type, an
 * unknown setting or a value outside its field's rules is refused or dropped.
 * There is no field that takes markup or script, which is what makes "no
 * custom code" a property of the data rather than a promise.
 *
 * Two kinds:
 *   - live: drawn from the restaurant's own data at view time (menu, online
 *     ordering, branches, contact). Their settings change how it is shown,
 *     never the data itself.
 *   - content: what the owner writes - headings, text, images.
 */
final class SiteSections
{
    /** Most sections one page may hold. */
    public const MAX_SECTIONS = 40;

    /** Background choices, shared by every section; colours come from the theme. */
    private const BACKGROUNDS = ['default', 'muted', 'brand', 'dark'];

    /** Most photos a Gallery may hold. */
    public const MAX_GALLERY = 12;

    /**
     * What can be placed. Given the restaurant's modules, components that need
     * one it does not have (Reservations needs CRM) are left out.
     *
     * @param  list<string>|null  $modules
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(?array $modules = null): array
    {
        $all = self::all();

        return $modules === null
            ? $all
            : array_filter($all, fn (array $def) => ! isset($def['requires']) || in_array($def['requires'], $modules, true));
    }

    /** @return array<string, array<string, mixed>> */
    private static function all(): array
    {
        $background = ['type' => 'enum', 'label' => 'Background', 'options' => self::BACKGROUNDS, 'default' => 'default'];

        return [
            // ---- Live: from the restaurant's data ----------------------------
            'menu' => [
                'label' => 'Menu',
                'kind' => 'live',
                'description' => 'Your menu by category, in the order set in Catalog > Categories. Prices and items update by themselves.',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => 'Our Menu'],
                    'layout' => ['type' => 'enum', 'label' => 'Layout', 'options' => ['grid', 'list'], 'default' => 'grid'],
                    'show_prices' => ['type' => 'bool', 'label' => 'Show prices', 'default' => true],
                    'show_images' => ['type' => 'bool', 'label' => 'Show photos', 'default' => true],
                    'categories' => ['type' => 'categories', 'label' => 'Categories', 'default' => []],
                    'background' => $background,
                ],
            ],
            'order_button' => [
                'label' => 'Order online',
                'kind' => 'live',
                'description' => 'A button to your online ordering page.',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => 'Hungry? Order online'],
                    'label' => ['type' => 'text', 'label' => 'Button text', 'max' => 40, 'default' => 'Order now'],
                    'background' => array_merge($background, ['default' => 'brand']),
                ],
            ],
            'online_order' => [
                'label' => 'Ordering',
                'kind' => 'live',
                'description' => 'Your full online ordering - menu, cart and checkout - inside your website, in its colours. Orders arrive like any online order.',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => 'Heading (optional)', 'max' => 120, 'default' => ''],
                ],
            ],
            'branches' => [
                'label' => 'Branches',
                'kind' => 'live',
                'description' => 'Your branches with address and phone, from Locations.',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => 'Visit us'],
                    'background' => $background,
                ],
            ],
            'map' => [
                'label' => 'Map',
                'kind' => 'live',
                'description' => 'Google Maps for your branches, from Locations. With several branches, guests pick one from the list beside the map.',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => 'Find us'],
                    'locations' => ['type' => 'locations', 'label' => 'Branches (none ticked shows all)', 'default' => []],
                    'layout' => ['type' => 'enum', 'label' => 'Layout', 'options' => ['list', 'grid'], 'default' => 'list'],
                    'height' => ['type' => 'enum', 'label' => 'Map height', 'options' => ['small', 'medium', 'large'], 'default' => 'medium'],
                    'background' => $background,
                ],
            ],
            'contact' => [
                'label' => 'Contact',
                'kind' => 'live',
                'description' => 'Phone, email and address from Settings > Restaurant identity.',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => 'Get in touch'],
                    'background' => $background,
                ],
            ],
            'featured' => [
                'label' => 'Featured dishes',
                'kind' => 'live',
                'description' => 'A few dishes to show off, with photos and prices from your catalog.',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => 'Chef\'s favourites'],
                    'products' => ['type' => 'products', 'label' => 'Dishes (none chosen shows dishes with photos)', 'default' => []],
                    'count' => ['type' => 'enum', 'label' => 'How many', 'options' => ['3', '6', '9'], 'default' => '3'],
                    'show_prices' => ['type' => 'bool', 'label' => 'Show prices', 'default' => true],
                    'background' => $background,
                ],
            ],
            'reviews' => [
                'label' => 'Reviews',
                'kind' => 'live',
                'description' => 'Google reviews you chose to show, from Website > Reviews.',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => 'What our guests say'],
                    'count' => ['type' => 'enum', 'label' => 'How many', 'options' => ['3', '6'], 'default' => '3'],
                    'background' => array_merge($background, ['default' => 'muted']),
                ],
            ],
            'reservations' => [
                'label' => 'Reservations',
                'kind' => 'live',
                'requires' => 'crm',
                'description' => 'A booking form. Requests arrive in CRM > Reservations as pending, for you to confirm.',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => 'Book a table'],
                    'intro' => ['type' => 'textarea', 'label' => 'Text above the form', 'max' => 300, 'default' => 'Tell us when you are coming and we will confirm by phone.'],
                    'background' => $background,
                ],
            ],
            'social' => [
                'label' => 'Social links',
                'kind' => 'live',
                'description' => 'Your social pages, from Website > Social links.',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => 'Follow us'],
                    'background' => $background,
                ],
            ],

            // ---- Content: written by the owner -------------------------------
            'hero' => [
                'label' => 'Banner',
                'kind' => 'content',
                'description' => 'A large heading with a background photo and a button.',
                'fields' => [
                    'title' => ['type' => 'text', 'label' => 'Title', 'max' => 120, 'default' => 'Welcome'],
                    'subtitle' => ['type' => 'textarea', 'label' => 'Subtitle', 'max' => 300, 'default' => ''],
                    'image' => ['type' => 'image', 'label' => 'Background photo', 'default' => ''],
                    'align' => ['type' => 'enum', 'label' => 'Alignment', 'options' => ['center', 'left'], 'default' => 'center'],
                    'button' => ['type' => 'enum', 'label' => 'Button', 'options' => ['order', 'menu', 'none'], 'default' => 'order'],
                    'button_label' => ['type' => 'text', 'label' => 'Button text', 'max' => 40, 'default' => 'Order now'],
                ],
            ],
            'text' => [
                'label' => 'Text',
                'kind' => 'content',
                'description' => 'A heading and paragraphs.',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => ''],
                    'body' => ['type' => 'textarea', 'label' => 'Text', 'max' => 4000, 'default' => ''],
                    'align' => ['type' => 'enum', 'label' => 'Alignment', 'options' => ['left', 'center'], 'default' => 'left'],
                    'background' => $background,
                ],
            ],
            'image' => [
                'label' => 'Image',
                'kind' => 'content',
                'description' => 'A photo with an optional caption.',
                'fields' => [
                    'image' => ['type' => 'image', 'label' => 'Photo', 'default' => ''],
                    'caption' => ['type' => 'text', 'label' => 'Caption', 'max' => 200, 'default' => ''],
                    'width' => ['type' => 'enum', 'label' => 'Width', 'options' => ['contained', 'full'], 'default' => 'contained'],
                ],
            ],
            'image_text' => [
                'label' => 'Image and text',
                'kind' => 'content',
                'description' => 'A photo beside a heading, text and an optional button.',
                'fields' => [
                    'image' => ['type' => 'image', 'label' => 'Photo', 'default' => ''],
                    'image_side' => ['type' => 'enum', 'label' => 'Photo on the', 'options' => ['left', 'right'], 'default' => 'left'],
                    'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => ''],
                    'body' => ['type' => 'textarea', 'label' => 'Text', 'max' => 2000, 'default' => ''],
                    'button' => ['type' => 'enum', 'label' => 'Button', 'options' => ['none', 'order', 'menu'], 'default' => 'none'],
                    'button_label' => ['type' => 'text', 'label' => 'Button text', 'max' => 40, 'default' => 'Order now'],
                    'background' => $background,
                ],
            ],
            'gallery' => [
                'label' => 'Gallery',
                'kind' => 'content',
                'description' => 'A grid of photos.',
                'fields' => [
                    'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => ''],
                    'images' => ['type' => 'images', 'label' => 'Photos', 'max' => self::MAX_GALLERY, 'default' => []],
                    'columns' => ['type' => 'enum', 'label' => 'Columns', 'options' => ['2', '3', '4'], 'default' => '3'],
                    'background' => $background,
                ],
            ],
            'button' => [
                'label' => 'Button',
                'kind' => 'content',
                'description' => 'A single button - to ordering, the menu, a phone call or another page.',
                'fields' => [
                    'label' => ['type' => 'text', 'label' => 'Button text', 'max' => 40, 'default' => 'Order now'],
                    'target' => ['type' => 'enum', 'label' => 'Goes to', 'options' => ['order', 'menu', 'call', 'page'], 'default' => 'order'],
                    'page' => ['type' => 'page', 'label' => 'Page (when it goes to a page)', 'default' => ''],
                    'align' => ['type' => 'enum', 'label' => 'Alignment', 'options' => ['center', 'left'], 'default' => 'center'],
                    'background' => $background,
                ],
            ],
            'spacer' => [
                'label' => 'Space',
                'kind' => 'content',
                'description' => 'Empty space between sections.',
                'fields' => [
                    'size' => ['type' => 'enum', 'label' => 'Size', 'options' => ['small', 'medium', 'large'], 'default' => 'medium'],
                ],
            ],
        ];
    }

    /**
     * A page's sections, checked and cleaned. Unknown settings are dropped,
     * missing ones take their default; an unknown type or a bad value is
     * refused with the section's position in the message.
     *
     * @param  list<string>|null  $modules  the restaurant's modules; components needing others are refused
     * @return list<array{id: string, type: string, props: array<string, mixed>}>
     */
    public static function clean(mixed $sections, ?array $modules = null): array
    {
        if (! is_array($sections) || ! array_is_list($sections)) {
            throw ValidationException::withMessages(['sections' => ['A page is a list of sections.']]);
        }

        if (count($sections) > self::MAX_SECTIONS) {
            throw ValidationException::withMessages(['sections' => ['A page can hold up to '.self::MAX_SECTIONS.' sections.']]);
        }

        $definitions = self::definitions($modules);
        $clean = [];
        $ids = [];

        foreach ($sections as $i => $section) {
            $where = 'Section '.($i + 1);
            $type = is_array($section) ? ($section['type'] ?? null) : null;

            if (! is_string($type) || ! isset($definitions[$type])) {
                throw ValidationException::withMessages(['sections' => ["{$where} is not a component the builder offers."]]);
            }

            $id = is_string($section['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $section['id'])
                ? $section['id']
                : bin2hex(random_bytes(6));

            // Unique within the page, so the editor can address each one.
            while (isset($ids[$id])) {
                $id = bin2hex(random_bytes(6));
            }
            $ids[$id] = true;

            $given = is_array($section['props'] ?? null) ? $section['props'] : [];
            $props = [];

            foreach ($definitions[$type]['fields'] as $name => $field) {
                $props[$name] = array_key_exists($name, $given)
                    ? self::value($field, $given[$name], "{$where} ({$definitions[$type]['label']}): {$field['label']}")
                    : $field['default'];
            }

            $clean[] = ['id' => $id, 'type' => $type, 'props' => $props];
        }

        return $clean;
    }

    /** One setting, checked against its field. */
    private static function value(array $field, mixed $value, string $where): mixed
    {
        $fail = fn (string $why) => throw ValidationException::withMessages(['sections' => ["{$where} {$why}"]]);

        return match ($field['type']) {
            'text', 'textarea' => match (true) {
                $value === null => '',
                ! is_string($value) => $fail('must be text.'),
                mb_strlen($value) > $field['max'] => $fail("is longer than {$field['max']} characters."),
                // Plain text only: rendered escaped, so markup would only show
                // as literal characters - refused to say so plainly.
                $value !== strip_tags($value) => $fail('cannot contain HTML.'),
                default => $field['type'] === 'text' ? trim($value) : $value,
            },
            'enum' => in_array($value, $field['options'], true) ? $value : $fail('is not one of the choices.'),
            'bool' => is_bool($value) ? $value : $fail('must be on or off.'),
            'image' => match (true) {
                $value === null || $value === '' => '',
                is_string($value) && preg_match('#^site/[A-Za-z0-9/_.-]{1,200}$#', $value) && ! str_contains($value, '..') => $value,
                default => $fail('must be a photo uploaded here.'),
            },
            'categories', 'products', 'locations' => is_array($value) && array_is_list($value) && count($value) <= 100 && array_filter($value, fn ($v) => ! is_int($v)) === []
                ? array_values(array_unique($value))
                : $fail('must be a list to choose from.'),
            'images' => is_array($value) && array_is_list($value) && count($value) <= $field['max']
                ? array_map(fn ($path) => self::value(['type' => 'image', 'label' => $field['label']], $path, $where), array_values($value))
                : $fail("can hold up to {$field['max']} photos."),
            // A page address in this site; a page that is later deleted just
            // links home, so only the shape is checked.
            'page' => match (true) {
                $value === null || $value === '' => '',
                is_string($value) && strlen($value) <= 80 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) => $value,
                default => $fail('is not a page of this site.'),
            },
            default => $fail('has an unknown kind of setting.'),
        };
    }

    /**
     * Ready-made layouts an owner can start a page from. Every one is built
     * from components, so it is checked like any page and fills itself from
     * the restaurant's data; Reservations only appears for restaurants with
     * CRM.
     *
     * @param  list<string>|null  $modules
     * @return array<string, array{label: string, description: string, sections: list<array<string, mixed>>}>
     */
    public static function templates(string $restaurantName, ?array $modules = null): array
    {
        $name = mb_substr($restaurantName, 0, 120);
        $withReservations = $modules === null || in_array('crm', $modules, true);

        $templates = [
            'classic' => [
                'label' => 'Classic restaurant',
                'description' => 'Banner, favourites, the full menu, reviews and your branches.',
                'sections' => [
                    ['type' => 'hero', 'props' => ['title' => $name, 'subtitle' => 'Fresh food, made to order.']],
                    ['type' => 'featured'],
                    ['type' => 'menu'],
                    ['type' => 'reviews'],
                    ['type' => 'branches'],
                    ['type' => 'contact'],
                ],
            ],
            'cafe' => [
                'label' => 'Café',
                'description' => 'A warm welcome, your story with a photo, a light menu list and social links.',
                'sections' => [
                    ['type' => 'hero', 'props' => ['title' => $name, 'subtitle' => 'Coffee, cakes and good company.', 'button' => 'menu', 'button_label' => 'See the menu']],
                    ['type' => 'image_text', 'props' => ['heading' => 'Our café', 'body' => 'Tell your guests what makes your café special.']],
                    ['type' => 'featured', 'props' => ['count' => '6', 'heading' => 'Guest favourites']],
                    ['type' => 'menu', 'props' => ['layout' => 'list', 'show_images' => false]],
                    ['type' => 'social'],
                    ['type' => 'contact'],
                ],
            ],
            'fast_food' => [
                'label' => 'Fast food & takeaway',
                'description' => 'Ordering first: a big order button, best sellers and the menu in a grid.',
                'sections' => [
                    ['type' => 'hero', 'props' => ['title' => $name, 'subtitle' => 'Hot, fast and ready when you are.', 'button_label' => 'Order now']],
                    ['type' => 'order_button', 'props' => ['heading' => 'Order online for pickup or delivery']],
                    ['type' => 'featured', 'props' => ['count' => '6', 'heading' => 'Best sellers']],
                    ['type' => 'menu'],
                    ['type' => 'branches'],
                ],
            ],
            'fine_dining' => [
                'label' => 'Fine dining',
                'description' => 'An elegant welcome, your story, signature dishes and table booking.',
                'sections' => array_values(array_filter([
                    ['type' => 'hero', 'props' => ['title' => $name, 'subtitle' => 'An evening to remember.', 'align' => 'left', 'button' => 'none']],
                    ['type' => 'text', 'props' => ['heading' => 'Our story', 'body' => 'Tell your guests about your kitchen, your chef and your ingredients.', 'align' => 'center']],
                    ['type' => 'featured', 'props' => ['heading' => 'Signature dishes']],
                    $withReservations ? ['type' => 'reservations'] : null,
                    ['type' => 'reviews'],
                    ['type' => 'contact'],
                ])),
            ],
        ];

        foreach ($templates as $key => $template) {
            $templates[$key]['sections'] = self::clean($template['sections'], $modules);
        }

        return $templates;
    }
}
