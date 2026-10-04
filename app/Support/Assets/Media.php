<?php

namespace App\Support\Assets;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Where uploaded files live.
 *
 * New uploads go to the media disk (MEDIA_DISK: `public` on a developer's
 * machine and in tests, `s3` once a bucket is configured). Everything already
 * on the local `public` disk stays there and keeps working: the files written
 * before the switch, and the demo imagery the seeders copy in from
 * database/seeders/images - which is shared by every demo restaurant and is
 * deliberately never uploaded to the bucket.
 *
 * Rows store a bare path either way ("foods/abc.jpg"), and every client asks
 * for it as /storage/{path}. A file that exists locally is served straight off
 * the disk; one that does not reaches StorageController, which sends the
 * browser to the bucket. So no row and no client needs to know which disk a
 * file is on - only this class does.
 */
class Media
{
    /**
     * The disk new uploads are written to.
     */
    public static function disk(): string
    {
        return (string) config('filesystems.media_disk', 'public');
    }

    /**
     * Whether uploads leave the machine - i.e. the media disk is not the local
     * public disk.
     */
    public static function isRemote(): bool
    {
        return self::disk() !== 'public';
    }

    /**
     * The disks a stored path may be on, local first: a local file is the
     * cheaper answer, and it is where old uploads and demo images are.
     *
     * @return list<string>
     */
    public static function disks(): array
    {
        return array_values(array_unique(['public', self::disk()]));
    }

    /**
     * The disk holding this path, or null if it is on none of them.
     */
    public static function locate(string $path): ?Filesystem
    {
        foreach (self::disks() as $name) {
            $disk = Storage::disk($name);

            if ($disk->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    public static function exists(string $path): bool
    {
        return self::locate($path) !== null;
    }

    /**
     * Deletes the path from whichever disk has it.
     */
    public static function delete(string $path): bool
    {
        $disk = self::locate($path);

        return $disk !== null && $disk->delete($path);
    }
}
