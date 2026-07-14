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
        $this->call([
            LocationSeeder::class,
            DemoUserSeeder::class,
            DemoEmployeeSeeder::class,
            ProductSeeder::class,
            CustomerSeeder::class,
            TableSeeder::class,
            OrderSeeder::class,
            RecipeSeeder::class,
            DemoHrSeeder::class,
        ]);
    }
}
