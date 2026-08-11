<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * env() must only ever be called from config/.
 *
 * The deploy runs `php artisan optimize`, which caches the config. From that
 * moment env() returns null everywhere else - so a value read straight from a
 * view or a controller silently becomes empty in production while working
 * perfectly in local dev.
 *
 * This is not theoretical: the pricing page's WhatsApp buttons shipped as
 * "https://wa.me/?text=..." with no number, and the footer showed a
 * placeholder phone number to real visitors, for exactly this reason.
 */
class EnvUsageTest extends TestCase
{
    #[Test]
    public function env_is_only_read_from_config_files(): void
    {
        $offenders = [];

        foreach (['app', 'routes', 'resources', 'database', 'bootstrap'] as $dir) {
            $path = base_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                foreach (file($file->getPathname()) as $number => $line) {
                    // Skip comments - a line explaining this rule is not a breach of it.
                    if (preg_match('/^\s*(\/\/|\*|#|\/\*)/', $line)) {
                        continue;
                    }

                    // The lookbehind also excludes '.', so prose like
                    // "set DEMO_MODE in .env (and restart)" is not mistaken
                    // for a call. getenv() is excluded by the \w rule.
                    if (preg_match('/(?<![\w>$.])env\s*\(/', $line)) {
                        $relative = str_replace(base_path().'/', '', $file->getPathname());
                        $offenders[] = $relative.':'.($number + 1).' -> '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['env() found outside config/. Move the value into a config file and read it with config():'],
            $offenders,
        )));
    }
}
