<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tables = [
            ['location_id' => 1, 'name' => 'Table 1', 'capacity' => 2],
            ['location_id' => 1, 'name' => 'Table 2', 'capacity' => 2],
            ['location_id' => 1, 'name' => 'Table 3', 'capacity' => 4],
            ['location_id' => 1, 'name' => 'Table 4', 'capacity' => 4],
            ['location_id' => 1, 'name' => 'Table 5', 'capacity' => 6],
            ['location_id' => 1, 'name' => 'VIP Table 1', 'capacity' => 8],
            ['location_id' => 1, 'name' => 'VIP Table 2', 'capacity' => 8],
            ['location_id' => 1, 'name' => 'Patio Table 1', 'capacity' => 4],
            ['location_id' => 1, 'name' => 'Patio Table 2', 'capacity' => 4],

            // Dhanmondi Branch
            ['location_id' => 2, 'name' => 'Dhanmondi T1', 'capacity' => 2],
            ['location_id' => 2, 'name' => 'Dhanmondi T2', 'capacity' => 4],
            ['location_id' => 2, 'name' => 'Dhanmondi T3', 'capacity' => 4],
            ['location_id' => 2, 'name' => 'Balcony 1', 'capacity' => 6],

            // Gulshan Branch
            ['location_id' => 3, 'name' => 'Gulshan T1', 'capacity' => 4],
            ['location_id' => 3, 'name' => 'Gulshan T2', 'capacity' => 4],
            ['location_id' => 3, 'name' => 'Rooftop T1', 'capacity' => 2],
            ['location_id' => 3, 'name' => 'Rooftop T2', 'capacity' => 8],

            // Uttara Branch
            ['location_id' => 4, 'name' => 'Uttara T1', 'capacity' => 4],
            ['location_id' => 4, 'name' => 'Uttara T2', 'capacity' => 6],
            ['location_id' => 4, 'name' => 'Family Table', 'capacity' => 8],
        ];

        foreach ($tables as $table) {
            DB::table('tables')->updateOrInsert(
                ['name' => $table['name'], 'location_id' => $table['location_id']],
                $table
            );
        }
    }
}
