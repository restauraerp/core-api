<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Add additional locations for demo
        \App\Models\Location::updateOrCreate(
            ['name' => 'Dhanmondi Branch'],
            [
                'type' => 'branch',
                'address' => 'Dhanmondi, Dhaka',
                'phone' => '+8801987654321',
                'is_active' => true,
            ]
        );
        
        \App\Models\Location::updateOrCreate(
            ['name' => 'Gulshan Branch'],
            [
                'type' => 'branch',
                'address' => 'Gulshan, Dhaka',
                'phone' => '+8801711223344',
                'is_active' => true,
            ]
        );

        \App\Models\Location::updateOrCreate(
            ['name' => 'Uttara Branch'],
            [
                'type' => 'branch',
                'address' => 'Uttara, Dhaka',
                'phone' => '+8801611223355',
                'is_active' => true,
            ]
        );

        $this->call([
            MenuSeeder::class,
            CustomerSeeder::class,
            TableSeeder::class,
            OrderSeeder::class,
            RecipeSeeder::class,
        ]);
    }
}
