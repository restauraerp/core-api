<?php

namespace App\Console\Commands;

use App\Support\Assets\ManagedAssets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Deletes uploaded files that no row points at any more.
 *
 * Uploads are written under a fresh random name each time, so before the
 * models started releasing replaced files (see TracksUploadedAssets) every
 * changed photo left its predecessor behind. Those files belong to nobody:
 * `tenants:remove` resolves a restaurant's assets through its rows, so an
 * orphan is invisible to it and survives a permanent deletion.
 *
 * This is the sweep for what accumulated before that, and a safety net for
 * anything that slips through a path which bypasses Eloquent.
 */
class PruneOrphanAssets extends Command
{
    protected $signature = 'storage:prune-orphans
        {--force : Delete without asking}
        {--dry-run : Report what would be deleted without deleting anything}
        {--older-than=60 : Only consider files untouched for this many minutes}';

    protected $description = 'Delete uploaded files no database row references [--force --dry-run --older-than=]';

    protected $help = <<<'HELP'
        Compares every file in the upload directories against every column that stores
        an upload path. Anything on disk that no row references is an orphan and is
        deleted.

        Only the upload directories are considered, so a file placed on the public disk
        deliberately - and referenced from code rather than a row - is never touched.

        Recent files are skipped (--older-than, 60 minutes by default) because an upload
        in flight is on disk for a moment before its row exists.

        Examples:
          <info>php artisan storage:prune-orphans --dry-run</info>       list them, delete nothing
          <info>php artisan storage:prune-orphans</info>                 prompt, then delete
          <info>php artisan storage:prune-orphans --force</info>         no prompt (use in scripts)
        HELP;

    public function __construct(private ManagedAssets $assets)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $referenced = array_flip($this->assets->referencedPaths());
        $cutoff = now()->subMinutes(max(0, (int) $this->option('older-than')))->getTimestamp();

        $orphans = [];
        $bytes = 0;
        $skippedRecent = 0;

        foreach (ManagedAssets::UPLOAD_DIRECTORIES as $directory) {
            foreach ($disk->allFiles($directory) as $path) {
                if (isset($referenced[$path]) || Str::startsWith(basename($path), '.')) {
                    continue;
                }

                // An upload lands on disk before its row is committed. Without
                // this window a sweep running at the wrong moment would delete
                // a file somebody is in the middle of uploading.
                if ($disk->lastModified($path) > $cutoff) {
                    $skippedRecent++;

                    continue;
                }

                $orphans[] = $path;
                $bytes += $disk->size($path);
            }
        }

        if ($orphans === []) {
            $this->info('No orphaned files. Nothing to do.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line(count($orphans).' orphaned file(s), '.$this->humanBytes($bytes).':');

        foreach (array_slice($orphans, 0, 20) as $path) {
            $this->line('  '.$path);
        }

        if (count($orphans) > 20) {
            $this->line('  ... and '.(count($orphans) - 20).' more.');
        }

        if ($skippedRecent > 0) {
            $this->line("  ({$skippedRecent} recent file(s) skipped - they may still be mid-upload.)");
        }

        $this->line('');

        if ($this->option('dry-run')) {
            $this->comment('Dry run - nothing was deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Delete these files?', false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($orphans as $path) {
            if ($disk->delete($path)) {
                $deleted++;

                continue;
            }

            $this->warn("  Could not delete: {$path}");
        }

        $this->info("{$deleted} file(s) deleted, ".$this->humanBytes($bytes).' reclaimed.');

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return $bytes.' B';
    }
}
