<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tag;
use Illuminate\Support\Str;

class CatalogTagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tags = [
            'Spicy',
            'Vegan',
            'Vegetarian',
            'Gluten-Free',
            'Bestseller',
            'New',
            'Halal',
            'Dairy-Free',
            'Nut-Free',
            'Chef\'s Special',
            'Organic',
            'Low Carb',
            'Healthy'
        ];

        foreach ($tags as $tag) {
            Tag::firstOrCreate(
                ['slug' => Str::slug($tag)],
                ['name' => $tag]
            );
        }
    }
}
