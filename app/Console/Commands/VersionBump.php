<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;

class VersionBump extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'version:bump';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bump the application version in .env.example and copy it to .env';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $examplePath = base_path('.env.example');
        
        if (!File::exists($examplePath)) {
            $this->error('Cannot find .env.example file.');
            return;
        }

        $exampleContent = File::get($examplePath);
        
        if (preg_match('/^APP_VERSION=(.*)$/m', $exampleContent, $matches)) {
            $currentVersion = trim($matches[1]);
            
            $parts = explode('.', $currentVersion);
            if (count($parts) !== 3) {
                $parts = [1, 0, 0];
            }
            
            $x = (int)$parts[0];
            $y = (int)$parts[1];
            $z = (int)$parts[2];
            
            $z++;
            
            if ($z > 99) {
                $z = 0;
                $y++;
            }
            
            if ($y > 99) {
                $y = 0;
                $x++;
            }
            
            if ($x > 9) {
                $this->error("Cannot bump version: First part of version cannot go above 9.");
                return;
            }
            
            $newVersion = sprintf('%d.%02d.%02d', $x, $y, $z);
            
            // Update .env.example
            $newExampleContent = preg_replace('/^APP_VERSION=.*$/m', 'APP_VERSION=' . $newVersion, $exampleContent);
            File::put($examplePath, $newExampleContent);
            $this->line("Bumped .env.example to version {$newVersion}");
            
            // Run version:copy
            $this->call('version:copy');
            
            $this->info('Version bumped and copied successfully!');
        } else {
            $this->warn('Could not find APP_VERSION in .env.example.');
        }
    }
}
