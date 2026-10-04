<?php

namespace App\Http\Controllers;

use App\Support\Assets\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /storage/{path} for a file that is not on this server's disk.
 *
 * Every client asks for an upload as /storage/{path}. A file on the local
 * public disk - an older upload, or a demo image - is served off the disk
 * before PHP is reached (Caddy's file matcher in production, `artisan serve`
 * locally), so only a miss gets here. With uploads going to the bucket, a miss
 * is normally a newer upload, and the browser is sent there. That keeps every
 * stored path, and every client building /storage URLs, exactly as it was.
 */
class StorageController extends Controller
{
    /** How long a browser may reuse a redirect to a public bucket URL. */
    private const PUBLIC_CACHE_SECONDS = 86400;

    /** Lifetime of a signed URL to a private bucket, and how long the redirect may be cached (less, so it never outlives the URL). */
    private const SIGNED_URL_MINUTES = 120;

    private const SIGNED_CACHE_SECONDS = 3600;

    public function __invoke(string $path): Response
    {
        $path = ltrim($path, '/');

        abort_if($path === '' || Str::contains($path, ['..', "\0"]), 404);

        // Normally served before PHP; this covers a server without the
        // public/storage link.
        $local = Storage::disk('public');
        if ($local->exists($path)) {
            return response()->file($local->path($path), ['Cache-Control' => 'public, max-age=604800']);
        }

        abort_unless(Media::isRemote(), 404);

        $disk = Storage::disk(Media::disk());

        // A public bucket (or a CDN in front of one) is named by AWS_URL; the
        // redirect is then stable and can be cached for a day. Without it the
        // bucket is private and the browser gets a short-lived signed URL.
        // Existence is not checked first: that would cost a round trip to the
        // bucket on every image, and the bucket answers 404 for a missing
        // object itself.
        if (config('filesystems.disks.'.Media::disk().'.url')) {
            return redirect()->away($disk->url($path), 302, [
                'Cache-Control' => 'public, max-age='.self::PUBLIC_CACHE_SECONDS,
            ]);
        }

        return redirect()->away($disk->temporaryUrl($path, now()->addMinutes(self::SIGNED_URL_MINUTES)), 302, [
            'Cache-Control' => 'private, max-age='.self::SIGNED_CACHE_SECONDS,
        ]);
    }
}
