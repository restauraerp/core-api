<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Location;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define source and destination paths for seed assets
        $assetsPath = database_path('seeders/assets');
        $publicStoragePath = storage_path('app/public');
        
        // Ensure destination directories exist
        if (!\Illuminate\Support\Facades\File::exists("$publicStoragePath/locations")) {
            \Illuminate\Support\Facades\File::makeDirectory("$publicStoragePath/locations", 0755, true);
        }
        if (!\Illuminate\Support\Facades\File::exists("$publicStoragePath/locations_videos")) {
            \Illuminate\Support\Facades\File::makeDirectory("$publicStoragePath/locations_videos", 0755, true);
        }

        // Copy location images
        if (\Illuminate\Support\Facades\File::exists("$assetsPath/locations/exterior.png")) {
            \Illuminate\Support\Facades\File::copy("$assetsPath/locations/exterior.png", "$publicStoragePath/locations/exterior.png");
        }
        if (\Illuminate\Support\Facades\File::exists("$assetsPath/locations/interior.png")) {
            \Illuminate\Support\Facades\File::copy("$assetsPath/locations/interior.png", "$publicStoragePath/locations/interior.png");
        }
        
        // Copy location videos
        if (\Illuminate\Support\Facades\File::exists("$assetsPath/locations_videos/sample.mp4")) {
            \Illuminate\Support\Facades\File::copy("$assetsPath/locations_videos/sample.mp4", "$publicStoragePath/locations_videos/sample.mp4");
        }

        $locationsData = [
            [
                'name' => 'Banani Branch',
                'slug' => 'banani-branch',
                'type' => 'branch',
                'address' => 'Banani, Dhaka',
                'phone' => '+8801999999999',
                'is_active' => true,
            ],
            [
                'name' => 'Dhanmondi Branch',
                'slug' => 'dhanmondi-branch',
                'type' => 'branch',
                'address' => 'Dhanmondi, Dhaka',
                'phone' => '+8801987654321',
                'is_active' => true,
            ],
            [
                'name' => 'Gulshan Branch',
                'slug' => 'gulshan-branch',
                'type' => 'branch',
                'address' => 'Gulshan, Dhaka',
                'phone' => '+8801711223344',
                'is_active' => true,
            ],
            [
                'name' => 'Uttara Branch',
                'slug' => 'uttara-branch',
                'type' => 'branch',
                'address' => 'Uttara, Dhaka',
                'phone' => '+8801611223355',
                'is_active' => true,
            ]
        ];

        foreach ($locationsData as $data) {
            $location = Location::updateOrCreate(['name' => $data['name']], $data);
            
            // Add featured image
            \App\Models\Image::updateOrCreate(
                ['imageable_id' => $location->id, 'imageable_type' => Location::class, 'type' => 'featured_image'],
                ['url' => 'locations/exterior.png']
            );

            // Add featured video
            \App\Models\Image::updateOrCreate(
                ['imageable_id' => $location->id, 'imageable_type' => Location::class, 'type' => 'featured_video'],
                ['url' => 'locations_videos/sample.mp4']
            );

            // Add gallery images
            \App\Models\Image::updateOrCreate(
                ['imageable_id' => $location->id, 'imageable_type' => Location::class, 'url' => 'locations/exterior.png', 'type' => 'image'],
                []
            );
            \App\Models\Image::updateOrCreate(
                ['imageable_id' => $location->id, 'imageable_type' => Location::class, 'url' => 'locations/interior.png', 'type' => 'image'],
                []
            );
        }
    }
}
