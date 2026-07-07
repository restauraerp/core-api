<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ProductCategory;
use Illuminate\Support\Str;

class CatalogCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            'Appetizers',
            'Main Courses',
            'Desserts',
            'Beverages',
            'Salads',
            'Soups',
            'Breakfast',
            'Lunch Specials',
            'Dinner',
            'Sides',
            'Pizza',
            'Pasta',
            'Grill',
            'Seafood',
            'Kids Menu'
        ];

        foreach ($categories as $category) {
            ProductCategory::firstOrCreate(
                ['slug' => Str::slug($category)],
                [
                    'name' => $category,
                    'is_active' => true,
                ]
            );
        }
    }
}
