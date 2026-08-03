<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Table;
use Illuminate\Database\Seeder;

/**
 * Dining tables for the demo restaurant's branches.
 *
 * Branches are addressed by their position within the current tenant rather
 * than by hardcoded location ids. The ids only lined up while there was exactly
 * one restaurant in the database; with a second demo tenant its branches get
 * ids 5..8, and every table here would have attached itself to the first
 * tenant's branches - or failed the foreign key outright.
 */
class TableSeeder extends Seeder
{
    public function run(): void
    {
        // Scoped to the current tenant by BelongsToTenant.
        $locations = Location::orderBy('id')->get()->values();

        if ($locations->isEmpty()) {
            $this->command?->warn('⚠️  TableSeeder skipped: no locations for this tenant.');

            return;
        }

        // Keyed by branch index, not branch id.
        $tablesByBranch = [
            0 => [
                ['Table 1', 2], ['Table 2', 2], ['Table 3', 4], ['Table 4', 4],
                ['Table 5', 6], ['VIP Table 1', 8], ['VIP Table 2', 8],
                ['Patio Table 1', 4], ['Patio Table 2', 4],
            ],
            1 => [
                ['Dhanmondi T1', 2], ['Dhanmondi T2', 4], ['Dhanmondi T3', 4], ['Balcony 1', 6],
            ],
            2 => [
                ['Gulshan T1', 4], ['Gulshan T2', 4], ['Rooftop T1', 2], ['Rooftop T2', 8],
            ],
            3 => [
                ['Uttara T1', 4], ['Uttara T2', 6], ['Family Table', 8],
            ],
        ];

        $created = 0;

        foreach ($tablesByBranch as $index => $tables) {
            $location = $locations->get($index);

            if ($location === null) {
                continue;
            }

            foreach ($tables as [$name, $capacity]) {
                Table::updateOrCreate(
                    ['name' => $name, 'location_id' => $location->id],
                    ['capacity' => $capacity],
                );
                $created++;
            }
        }

        $this->command?->info("✅ TableSeeder: {$created} tables across {$locations->count()} branch(es).");
    }
}
