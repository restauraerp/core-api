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
        Location::updateOrCreate(
            ['name' => 'Dhanmondi Branch'],
            [
                'type' => 'branch',
                'address' => 'Dhanmondi, Dhaka',
                'phone' => '+8801987654321',
                'is_active' => true,
            ]
        );
        
        Location::updateOrCreate(
            ['name' => 'Gulshan Branch'],
            [
                'type' => 'branch',
                'address' => 'Gulshan, Dhaka',
                'phone' => '+8801711223344',
                'is_active' => true,
            ]
        );

        Location::updateOrCreate(
            ['name' => 'Uttara Branch'],
            [
                'type' => 'branch',
                'address' => 'Uttara, Dhaka',
                'phone' => '+8801611223355',
                'is_active' => true,
            ]
        );
    }
}
