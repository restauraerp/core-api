<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class VersionCopy extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'version:copy';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Copy the application version from .env.example to .env file.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $examplePath = base_path('.env.example');
        $envPath = base_path('.env');
        
        if (!File::exists($examplePath)) {
            $this->error('Cannot find .env.example file.');
            return;
        }

        $exampleContent = File::get($examplePath);
        
        if (preg_match('/^APP_VERSION=(.*)$/m', $exampleContent, $matches)) {
            $version = trim($matches[1]);
            
            // Update .env
            if (File::exists($envPath)) {
                $envContent = File::get($envPath);
                if (preg_match('/^APP_VERSION=.*$/m', $envContent)) {
                    $newEnvContent = preg_replace('/^APP_VERSION=.*$/m', 'APP_VERSION=' . $version, $envContent);
                } else {
                    $newEnvContent = preg_replace('/^APP_NAME=(.*)$/m', "APP_NAME=$1\nAPP_VERSION=" . $version, $envContent);
                }
                File::put($envPath, $newEnvContent);
                $this->line("Updated .env to version {$version}");
                $this->info('Version copied successfully!');
            } else {
                $this->warn('Could not find .env file to update.');
            }
        } else {
            $this->warn('Could not find APP_VERSION in .env.example.');
        }
    }
}
