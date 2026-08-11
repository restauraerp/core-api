<?php

namespace App\Support\Assets;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where uploaded files are referenced from, and whether one is still in use.
 *
 * Uploads land in shared folders (foods/, users/, locations/, ...) rather than
 * a per-tenant prefix, so a file cannot be attributed to a restaurant by its
 * path - only by the rows pointing at it. Every question about an uploaded
 * file therefore comes back to this map, which is why it lives in one place
 * rather than being repeated by each caller.
 */
class ManagedAssets
{
    /**
     * Columns holding a path on the `public` disk.
     *
     * Deliberately excluded: cctv_cameras.stream_url, locations.map_url and
     * social_links.url all hold third-party URLs, not uploads.
     *
     * @var array<string, list<string>>
     */
    public const COLUMNS = [
        'images' => ['url'],
        'location_media' => ['url'],
        'product_media' => ['url'],
        'product_categories' => ['image_url'],
        'inventory_items' => ['image'],
        'users' => ['image_url'],
        'expenses' => ['receipt_url'],
    ];

    /**
     * website_settings is key/value, so its assets live in rows rather than
     * columns.
     *
     * @var list<string>
     */
    public const SETTING_KEYS = ['logo_url', 'favicon_url', 'cover_image_url'];

    /**
     * Directories uploads are written to, from the controllers' store() calls.
     *
     * The sweep is confined to these so a file placed on the disk deliberately
     * - and referenced from code rather than a row - is never a candidate for
     * deletion just because no query returned it.
     *
     * @var list<string>
     */
    public const UPLOAD_DIRECTORIES = [
        'foods',
        'images',
        'inventory',
        'locations',
        'locations_videos',
        'receipts',
        'users',
    ];

    /**
     * Whether any row still points at this path.
     *
     * @param  array<string, list<int|string>>  $ignore  Rows to treat as gone,
     *                                                   as table => list of ids.
     *                                                   Lets a caller ask about
     *                                                   a row it is deleting.
     */
    public function isReferenced(string $path, array $ignore = []): bool
    {
        $path = $this->normalise($path);

        if ($path === null) {
            return false;
        }

        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $query = DB::table($table)->where($column, $path);

                if (isset($ignore[$table]) && $ignore[$table] !== []) {
                    $query->whereNotIn('id', $ignore[$table]);
                }

                if ($query->exists()) {
                    return true;
                }
            }
        }

        return Schema::hasTable('website_settings')
            && DB::table('website_settings')
                ->whereIn('key', self::SETTING_KEYS)
                ->where('value', $path)
                ->exists();
    }

    /**
     * Deletes a file now that a row has stopped pointing at it.
     *
     * Call after the row has been saved or deleted, with the path it used to
     * hold. A no-op when the path is empty, remote, or still referenced -
     * seeded and demo imagery is shared between restaurants, so a file another
     * row still uses must survive.
     */
    public function release(?string $path, array $ignore = []): bool
    {
        $path = $this->normalise($path);

        if ($path === null || $this->isReferenced($path, $ignore)) {
            return false;
        }

        $disk = Storage::disk('public');

        return $disk->exists($path) && $disk->delete($path);
    }

    /**
     * @param  list<?string>  $paths
     */
    public function releaseMany(array $paths, array $ignore = []): int
    {
        $released = 0;

        foreach (array_unique(array_filter($paths)) as $path) {
            if ($this->release($path, $ignore)) {
                $released++;
            }
        }

        return $released;
    }

    /**
     * Every path currently referenced by a row, normalised.
     *
     * @return list<string>
     */
    public function referencedPaths(): array
    {
        $paths = [];

        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $paths = array_merge($paths, DB::table($table)->whereNotNull($column)->pluck($column)->all());
                }
            }
        }

        if (Schema::hasTable('website_settings')) {
            $paths = array_merge($paths, DB::table('website_settings')
                ->whereIn('key', self::SETTING_KEYS)
                ->pluck('value')
                ->all());
        }

        return $this->normaliseMany($paths);
    }

    /**
     * Trims a stored value to a disk path, or null if it is not one of ours.
     */
    public function normalise(?string $value): ?string
    {
        $value = ltrim(trim((string) $value), '/');

        if ($value === '') {
            return null;
        }

        // Remote URLs are not ours to delete, and a traversal segment in a
        // stored path is not something to act on.
        if (Str::startsWith($value, ['http://', 'https://', 'data:']) || Str::contains($value, '..')) {
            return null;
        }

        return $value;
    }

    /**
     * @param  list<?string>  $values
     * @return list<string>
     */
    public function normaliseMany(array $values): array
    {
        return collect($values)
            ->map(fn (?string $value) => $this->normalise($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
