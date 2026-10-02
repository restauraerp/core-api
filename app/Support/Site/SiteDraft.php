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
 * A restaurant's first website, put together from what it already has.
 *
 * Rules, not AI: nothing leaves the server and the result is the same every
 * time for the same data. Each section is only included when there is
 * something to show - Featured dishes needs photos, Reviews needs reviews
 * chosen for display, Reservations needs CRM, Branches needs more than one -
 * and the words are written from the restaurant's own name, menu categories
 * and branches. The result is a normal page, checked like any other, for the
 * owner to change.
 *
 * Runs inside the restaurant's tenant context.
 */
class SiteDraft
{
    /** Dishes with photos needed before Featured dishes is worth including. */
    private const MIN_FEATURED = 3;

    public function __construct(private Tenant $tenant) {}

    /**
     * @param  bool  $withPhoto  copy a dish photo in as the banner. Off for the
     *                           public fallback, which must not write files on a visitor's request.
     * @return array{sections: list<array<string, mixed>>, meta_description: string}
     */
    public function build(bool $withPhoto = true): array
    {
        $modules = $this->tenant->modules();
        $name = RestaurantBranding::current()['name'] ?? $this->tenant->name;

        $categories = $this->categoriesWithDishes();
        $withPhotos = Product::where('is_active', true)->whereHas('images')->with('images')->limit(12)->get();
        $branches = Location::where('is_active', true)->orderBy('name')->get(['id', 'name', 'address']);
        $hasReviews = GoogleReview::where('is_displayed', true)->exists();
        $delivers = in_array(Modules::DELIVERY, $modules, true);
        $books = in_array(Modules::CRM, $modules, true);

        // What the restaurant is known for: the three categories with the most
        // dishes, listed in catalog order.
        $known = $categories->sortByDesc('dish_count')->take(3)->pluck('id')->all();
        $dishes = $this->phrase($categories->whereIn('id', $known)->pluck('name')->all());
        $subtitle = $dishes !== '' ? "{$dishes}, made fresh every day." : 'Fresh food, made to order.';

        $sections = [[
            'type' => 'hero',
            'props' => [
                'title' => mb_substr($name, 0, 120),
                'subtitle' => $subtitle,
                'image' => $withPhoto ? $this->bannerPhoto($withPhotos) : '',
                'button_label' => 'Order now',
            ],
        ]];

        $sections[] = ['type' => 'text', 'props' => [
            'heading' => 'Welcome',
            'body' => $this->about($name, $dishes, $branches, $delivers, $books),
            'align' => 'center',
        ]];

        if ($withPhotos->count() >= self::MIN_FEATURED) {
            $sections[] = ['type' => 'featured', 'props' => [
                'heading' => 'Our favourites',
                'count' => $withPhotos->count() >= 6 ? '6' : '3',
            ]];
        }

        $sections[] = ['type' => 'menu'];
        $sections[] = ['type' => 'order_button', 'props' => [
            'heading' => $delivers ? 'Order online for pickup or delivery' : 'Order online for pickup',
        ]];

        if ($hasReviews) {
            $sections[] = ['type' => 'reviews'];
        }

        if ($books) {
            $sections[] = ['type' => 'reservations'];
        }

        if ($branches->count() > 1) {
            $sections[] = ['type' => 'branches', 'props' => ['heading' => 'Our branches']];
        }

        $sections[] = ['type' => 'contact'];

        return [
            'sections' => SiteSections::clean($sections, $modules),
            'meta_description' => mb_substr($this->metaDescription($name, $dishes, $branches, $delivers, $books), 0, 160),
        ];
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
     * Copies a dish photo into the site's own storage for the banner - the
     * banner only takes the site's uploads, and a copy keeps the site intact
     * if the dish's photo is later replaced.
     *
     * @param  Collection<int, Product>  $products
     */
    private function bannerPhoto(Collection $products): string
    {
        $disk = Storage::disk('public');

        foreach ($products as $product) {
            $source = $product->images->first()?->url;

            if (! $source || ! $disk->exists($source)) {
                continue;
            }

            $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION)) ?: 'jpg';
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }

            $target = 'site/'.$this->tenant->getKey().'/banner-'.$product->getKey().'.'.$extension;
            if (! $disk->exists($target)) {
                $disk->copy($source, $target);
            }

            return $target;
        }

        return '';
    }

    /** @param  Collection<int, Location>  $branches */
    private function about(string $name, string $dishes, Collection $branches, bool $delivers, bool $books): string
    {
        $lines = [];
        $lines[] = $dishes !== ''
            ? "Welcome to {$name}. We serve {$this->lower($dishes)}, cooked fresh to order."
            : "Welcome to {$name}. Everything is cooked fresh to order.";

        if ($branches->count() > 1) {
            $lines[] = 'Visit us at any of our '.$branches->count().' branches - '.$this->phrase($branches->pluck('name')->all()).'.';
        } elseif ($branches->first()?->address) {
            $lines[] = 'Visit us at '.$branches->first()->address.'.';
        }

        $ways = $delivers ? 'order online for pickup or delivery' : 'order online for pickup';
        $lines[] = $books ? "Book a table, or {$ways}." : ucfirst($ways).'.';

        return implode("\n\n", $lines);
    }

    /** @param  Collection<int, Location>  $branches */
    private function metaDescription(string $name, string $dishes, Collection $branches, bool $delivers, bool $books): string
    {
        $where = $branches->count() > 1 ? ' at '.$branches->count().' branches' : ($branches->first()?->address ? ' in '.$branches->first()->address : '');
        $what = $dishes !== '' ? " {$dishes}." : '';
        $how = 'Order online'.($delivers ? ' for pickup or delivery' : '').($books ? ', or book a table' : '').'.';

        return "{$name}{$where}.{$what} {$how}";
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
