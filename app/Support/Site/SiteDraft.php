<?php

namespace App\Support\Site;

use App\Models\GoogleReview;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Support\Billing\Modules;
use App\Support\Branding\RestaurantBranding;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * A restaurant's website, put together from what it already has.
 *
 * Rules, not AI: nothing leaves the server and the result is the same every
 * time for the same data. A full site of standard pages - Home, Menu, Order
 * online, Book a table, About, Gallery, Locations, Contact - each one a normal
 * page of components, checked like any other, for the owner to change. Within
 * a page a section is only included when there is something to show -
 * Featured dishes needs photos, Reviews needs reviews chosen for display - and
 * the words are written from the restaurant's own name, menu categories and
 * branches. Book a table is only for restaurants with CRM.
 *
 * Runs inside the restaurant's tenant context.
 */
class SiteDraft
{
    /** Dishes with photos needed before Featured dishes is worth including. */
    private const MIN_FEATURED = 3;

    /** The standard pages, in the order they appear in the site's navigation. */
    public const KINDS = ['home', 'menu', 'order', 'reservations', 'about', 'gallery', 'locations', 'contact'];

    private ?array $facts = null;

    public function __construct(private Tenant $tenant) {}

    /**
     * The home page.
     *
     * @return array{sections: list<array<string, mixed>>, meta_description: string}
     */
    public function build(bool $withPhoto = true): array
    {
        $home = $this->page('home', $withPhoto);

        return ['sections' => $home['sections'], 'meta_description' => $home['meta_description']];
    }

    /**
     * Every standard page this restaurant can have.
     *
     * @param  bool  $withPhoto  copy dish photos in for the banner and gallery.
     *                           Off for the public fallback, which must not write files on a visitor's request.
     * @return list<array{kind: string, slug: string, title: string, is_home: bool, sections: list<array<string, mixed>>, meta_description: string}>
     */
    public function pages(bool $withPhoto = true): array
    {
        return array_values(array_filter(array_map(
            fn (string $kind) => $this->page($kind, $withPhoto),
            self::KINDS,
        )));
    }

    /**
     * One standard page, or null when it does not apply (Book a table
     * without CRM).
     *
     * @return array{kind: string, slug: string, title: string, is_home: bool, sections: list<array<string, mixed>>, meta_description: string}|null
     */
    public function page(string $kind, bool $withPhoto = true): ?array
    {
        $f = $this->facts();
        $name = $f['name'];

        [$title, $sections, $description] = match ($kind) {
            'home' => ['Home', $this->home($withPhoto), $this->metaDescription()],
            'menu' => ['Menu', [
                ['type' => 'menu', 'props' => ['heading' => 'Our menu']],
                ['type' => 'order_button', 'props' => ['heading' => $f['delivers'] ? 'Order online for pickup or delivery' : 'Order online for pickup']],
            ], "The menu at {$name}".($f['dishes'] !== '' ? " - {$f['dishes']}" : '').'. Order online.'],
            'order' => ['Order online', [
                ['type' => 'online_order'],
            ], "Order online from {$name}".($f['delivers'] ? ' for pickup or delivery.' : ' for pickup.')],
            'reservations' => $f['books'] ? ['Book a table', [
                ['type' => 'reservations', 'props' => ['heading' => 'Book a table']],
                ['type' => 'contact', 'props' => ['heading' => 'Rather call?', 'background' => 'muted']],
            ], "Book a table at {$name}."] : null,
            'about' => ['About', $this->about($withPhoto), "About {$name}."],
            'gallery' => ['Gallery', [
                ['type' => 'gallery', 'props' => [
                    'heading' => 'Gallery',
                    'images' => $withPhoto ? $this->photos($f['photos'], 'gallery', 12) : [],
                ]],
                ['type' => 'order_button', 'props' => ['heading' => 'Like what you see?']],
            ], "Photos from {$name}."],
            'locations' => [$f['branches']->count() > 1 ? 'Locations' : 'Find us', [
                ['type' => 'map', 'props' => ['heading' => $f['branches']->count() > 1 ? 'Our branches' : 'Find us']],
                ['type' => 'order_button', 'props' => ['background' => 'muted']],
            ], $this->where() !== '' ? "{$name}{$this->where()}." : "Find {$name}."],
            'contact' => ['Contact', [
                ['type' => 'contact'],
                ['type' => 'map', 'props' => ['heading' => '', 'height' => 'small']],
                ['type' => 'social', 'props' => ['background' => 'muted']],
            ], "Contact {$name}."],
            default => throw new \InvalidArgumentException("Unknown page kind {$kind}"),
        } ?? [null, null, null];

        if ($title === null) {
            return null;
        }

        return [
            'kind' => $kind,
            'slug' => $kind,
            'title' => $title,
            'is_home' => $kind === 'home',
            'sections' => SiteSections::clean($sections, $f['modules']),
            'meta_description' => mb_substr($description, 0, 160),
        ];
    }

    /** What the pages are written from, read once. */
    private function facts(): array
    {
        if ($this->facts !== null) {
            return $this->facts;
        }

        $modules = $this->tenant->modules();
        $categories = $this->categoriesWithDishes();
        // What the restaurant is known for: the three categories with the most
        // dishes, listed in catalog order.
        $known = $categories->sortByDesc('dish_count')->take(3)->pluck('id')->all();

        return $this->facts = [
            'modules' => $modules,
            'name' => RestaurantBranding::current()['name'] ?? $this->tenant->name,
            'dishes' => $this->phrase($categories->whereIn('id', $known)->pluck('name')->all()),
            'photos' => Product::where('is_active', true)->whereHas('images')->with('images')->limit(12)->get(),
            'branches' => Location::where('is_active', true)->orderBy('name')->get(['id', 'name', 'address']),
            'reviews' => GoogleReview::where('is_displayed', true)->exists(),
            'delivers' => in_array(Modules::DELIVERY, $modules, true),
            'books' => in_array(Modules::CRM, $modules, true),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function home(bool $withPhoto): array
    {
        $f = $this->facts();
        $subtitle = $f['dishes'] !== '' ? "{$f['dishes']}, made fresh every day." : 'Fresh food, made to order.';

        $sections = [[
            'type' => 'hero',
            'props' => [
                'title' => mb_substr($f['name'], 0, 120),
                'subtitle' => $subtitle,
                'image' => $withPhoto ? ($this->photos($f['photos'], 'banner', 1)[0] ?? '') : '',
                'button_label' => 'Order now',
            ],
        ]];

        $sections[] = ['type' => 'text', 'props' => [
            'heading' => 'Welcome',
            'body' => $this->welcome(),
            'align' => 'center',
        ]];

        if ($f['photos']->count() >= self::MIN_FEATURED) {
            $sections[] = ['type' => 'featured', 'props' => [
                'heading' => 'Our favourites',
                'count' => $f['photos']->count() >= 6 ? '6' : '3',
            ]];
        }

        $sections[] = ['type' => 'button', 'props' => ['label' => 'See the full menu', 'target' => 'page', 'page' => 'menu']];
        $sections[] = ['type' => 'order_button', 'props' => [
            'heading' => $f['delivers'] ? 'Order online for pickup or delivery' : 'Order online for pickup',
        ]];

        if ($f['reviews']) {
            $sections[] = ['type' => 'reviews'];
        }

        return $sections;
    }

    /** @return list<array<string, mixed>> */
    private function about(bool $withPhoto): array
    {
        $f = $this->facts();
        $photo = $withPhoto ? ($this->photos($f['photos'], 'banner', 1)[0] ?? '') : '';
        $story = $photo !== '' ? 'image_text' : 'text';

        $sections = [[
            'type' => $story,
            'props' => ['heading' => 'Our story', 'body' => $this->story()] + ($photo !== '' ? ['image' => $photo] : ['align' => 'center']),
        ]];

        if ($f['reviews']) {
            $sections[] = ['type' => 'reviews', 'props' => ['background' => 'default']];
        }

        $sections[] = ['type' => 'order_button', 'props' => ['heading' => 'Come and taste it']];

        return $sections;
    }

    /** @return Collection<int, ProductCategory> categories with dishes on sale, in the catalog's order */
    private function categoriesWithDishes(): Collection
    {
        return ProductCategory::where('is_active', true)
            ->whereHas('products', fn ($q) => $q->where('is_active', true))
            ->withCount(['products as dish_count' => fn ($q) => $q->where('is_active', true)])
            ->ordered()
            ->get(['id', 'name']);
    }

    /**
     * Copies dish photos into the site's own storage - site images only take
     * the site's uploads, and a copy keeps the site intact if the dish's
     * photo is later replaced.
     *
     * @param  Collection<int, Product>  $products
     * @return list<string>
     */
    private function photos(Collection $products, string $prefix, int $limit): array
    {
        $disk = Storage::disk('public');
        $paths = [];

        foreach ($products as $product) {
            if (count($paths) >= $limit) {
                break;
            }

            $source = $product->images->first()?->url;

            if (! $source || ! $disk->exists($source)) {
                continue;
            }

            $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION)) ?: 'jpg';
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }

            $target = 'site/'.$this->tenant->getKey().'/'.$prefix.'-'.$product->getKey().'.'.$extension;
            if (! $disk->exists($target)) {
                $disk->copy($source, $target);
            }

            $paths[] = $target;
        }

        return $paths;
    }

    private function welcome(): string
    {
        $f = $this->facts();
        $lines = [];
        $lines[] = $f['dishes'] !== ''
            ? "Welcome to {$f['name']}. We serve {$this->lower($f['dishes'])}, cooked fresh to order."
            : "Welcome to {$f['name']}. Everything is cooked fresh to order.";

        if ($f['branches']->count() > 1) {
            $lines[] = 'Visit us at any of our '.$f['branches']->count().' branches - '.$this->phrase($f['branches']->pluck('name')->all()).'.';
        } elseif ($f['branches']->first()?->address) {
            $lines[] = 'Visit us at '.$f['branches']->first()->address.'.';
        }

        $ways = $f['delivers'] ? 'order online for pickup or delivery' : 'order online for pickup';
        $lines[] = $f['books'] ? "Book a table, or {$ways}." : ucfirst($ways).'.';

        return implode("\n\n", $lines);
    }

    private function story(): string
    {
        $f = $this->facts();
        $what = $f['dishes'] !== '' ? "{$this->lower($f['dishes'])}" : 'good food';

        return "{$f['name']} is about {$what}, cooked fresh to order and served the way we like to eat it ourselves."
            ."\n\nEvery dish is made in our own kitchen, from ingredients we choose every day. Come in and taste it for yourself.";
    }

    /** " at 3 branches", " in Gulshan 2, Dhaka", or "" */
    private function where(): string
    {
        $branches = $this->facts()['branches'];

        return $branches->count() > 1 ? ' at '.$branches->count().' branches' : ($branches->first()?->address ? ' in '.$branches->first()->address : '');
    }

    private function metaDescription(): string
    {
        $f = $this->facts();
        $what = $f['dishes'] !== '' ? " {$f['dishes']}." : '';
        $how = 'Order online'.($f['delivers'] ? ' for pickup or delivery' : '').($f['books'] ? ', or book a table' : '').'.';

        return "{$f['name']}{$this->where()}.{$what} {$how}";
    }

    /** "a", "a and b", "a, b and c" */
    private function phrase(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items)));

        return match (count($items)) {
            0 => '',
            1 => $items[0],
            default => implode(', ', array_slice($items, 0, -1)).' and '.end($items),
        };
    }

    /** Category names mid-sentence: "Main Courses" reads as "main courses". */
    private function lower(string $text): string
    {
        return mb_strtolower($text);
    }
}
