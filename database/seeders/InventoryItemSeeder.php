<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\InventoryItem;
use App\Models\Location;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class InventoryItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Demo Seeder
     */
    public function run(): void
    {
        // 0. Copy demo images to storage
        $sourcePath = database_path('seeders/images/inventory');
        $destinationPath = storage_path('app/public/inventory');

        if (!File::exists($destinationPath)) {
            File::makeDirectory($destinationPath, 0755, true);
        }

        if (File::exists($sourcePath)) {
            File::copyDirectory($sourcePath, $destinationPath);
        }

        $locations = Location::all();
        
        $items = [
            [
                'title' => 'Basmati Rice',
                'name' => 'Basmati Rice',
                'sku' => 'INV-RICE-001',
                'unit' => 'kg',
                'min_stock_level' => 50,
                'cost_per_unit' => 120,
                'image' => 'inventory/basmati_rice.png',
            ],
            [
                'title' => 'Chicken Breast',
                'name' => 'Chicken Breast',
                'sku' => 'INV-CHK-002',
                'unit' => 'kg',
                'min_stock_level' => 30,
                'cost_per_unit' => 250,
                'image' => 'inventory/chicken_breast.png',
            ],
            [
                'title' => 'Cooking Oil (Soyabean)',
                'name' => 'Soyabean Oil',
                'sku' => 'INV-OIL-003',
                'unit' => 'liter',
                'min_stock_level' => 20,
                'cost_per_unit' => 170,
                'image' => 'inventory/soyabean_oil.png',
            ],
            [
                'title' => 'Fresh Tomato',
                'name' => 'Tomato',
                'sku' => 'INV-VEG-004',
                'unit' => 'kg',
                'min_stock_level' => 10,
                'cost_per_unit' => 60,
                'image' => 'inventory/fresh_tomato.png',
            ],
            [
                'title' => 'Salt',
                'name' => 'Table Salt',
                'sku' => 'INV-SLT-005',
                'unit' => 'kg',
                'min_stock_level' => 5,
                'cost_per_unit' => 35,
                'image' => 'inventory/table_salt.png',
            ],
            [
                'title' => 'Red Onion',
                'name' => 'Red Onion',
                'sku' => 'INV-ONN-006',
                'unit' => 'kg',
                'min_stock_level' => 15,
                'cost_per_unit' => 45,
                'image' => 'inventory/red_onion.png',
            ],
            [
                'title' => 'Garlic',
                'name' => 'Garlic',
                'sku' => 'INV-GRL-007',
                'unit' => 'kg',
                'min_stock_level' => 10,
                'cost_per_unit' => 120,
                'image' => 'inventory/garlic.png',
            ],
            [
                'title' => 'Coriander Powder',
                'name' => 'Coriander Powder',
                'sku' => 'INV-CRP-008',
                'unit' => 'kg',
                'min_stock_level' => 5,
                'cost_per_unit' => 200,
                'image' => 'inventory/coriander_powder.png',
            ],
            [
                'title' => 'Coriander Leaves',
                'name' => 'Fresh Coriander',
                'sku' => 'INV-CRL-009',
                'unit' => 'bundle',
                'min_stock_level' => 20,
                'cost_per_unit' => 10,
                'image' => 'inventory/coriander_leaves.png',
            ],
            [
                'title' => 'Black Pepper',
                'name' => 'Whole Black Pepper',
                'sku' => 'INV-BKP-010',
                'unit' => 'kg',
                'min_stock_level' => 3,
                'cost_per_unit' => 800,
                'image' => 'inventory/black_pepper.png',
            ]
        ];

        foreach ($items as $itemData) {
            $item = InventoryItem::firstOrCreate(
                ['sku' => $itemData['sku']],
                $itemData
            );

            // Assign quantity based on locations
            if ($locations->count() > 0) {
                $syncData = [];
                foreach ($locations as $loc) {
                    $syncData[$loc->id] = [
                        'quantity' => rand(10, 100),
                        'is_active' => (bool)rand(0, 1) // Randomized activation
                    ];
                }
                $item->locations()->sync($syncData);
            }
        }
    }
}
