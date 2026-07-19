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
            ],
            [
                'title' => 'Ginger',
                'name' => 'Fresh Ginger',
                'sku' => 'INV-GIN-011',
                'unit' => 'kg',
                'min_stock_level' => 10,
                'cost_per_unit' => 150,
                'image' => 'inventory/ginger.png',
            ],
            [
                'title' => 'Green Chilli',
                'name' => 'Green Chilli',
                'sku' => 'INV-GCH-012',
                'unit' => 'kg',
                'min_stock_level' => 5,
                'cost_per_unit' => 80,
                'image' => 'inventory/green_chilli.png',
            ],
            [
                'title' => 'Potatoes',
                'name' => 'Potatoes',
                'sku' => 'INV-POT-013',
                'unit' => 'kg',
                'min_stock_level' => 50,
                'cost_per_unit' => 30,
                'image' => 'inventory/potatoes.png',
            ],
            [
                'title' => 'Butter',
                'name' => 'Butter Block',
                'sku' => 'INV-BTR-014',
                'unit' => 'kg',
                'min_stock_level' => 10,
                'cost_per_unit' => 600,
                'image' => 'inventory/butter.png',
            ],
            [
                'title' => 'Milk',
                'name' => 'Fresh Milk',
                'sku' => 'INV-MLK-015',
                'unit' => 'liter',
                'min_stock_level' => 20,
                'cost_per_unit' => 90,
                'image' => 'inventory/milk.png',
            ],
            [
                'title' => 'Ravioli Pasta',
                'name' => 'Fresh Ravioli',
                'sku' => 'INV-RAV-016',
                'unit' => 'kg',
                'min_stock_level' => 5,
                'cost_per_unit' => 450,
                'image' => 'inventory/ravioli_pasta.png',
            ],
            [
                'title' => 'Truffle Paste',
                'name' => 'Black Truffle Paste',
                'sku' => 'INV-TRF-017',
                'unit' => 'jar',
                'min_stock_level' => 5,
                'cost_per_unit' => 1200,
                'image' => 'inventory/truffle_paste.png',
            ],
            [
                'title' => 'Spicy Salami',
                'name' => 'Spicy Italian Salami',
                'sku' => 'INV-SAL-018',
                'unit' => 'kg',
                'min_stock_level' => 10,
                'cost_per_unit' => 1800,
                'image' => 'inventory/spicy_salami.png',
            ],
            [
                'title' => 'Chili Flakes',
                'name' => 'Red Chili Flakes',
                'sku' => 'INV-CHL-019',
                'unit' => 'kg',
                'min_stock_level' => 2,
                'cost_per_unit' => 400,
                'image' => 'inventory/chili_flakes.png',
            ],
            [
                'title' => 'Salmon Fillet',
                'name' => 'Fresh Salmon Fillet',
                'sku' => 'INV-SLM-020',
                'unit' => 'kg',
                'min_stock_level' => 10,
                'cost_per_unit' => 2500,
                'image' => 'inventory/salmon_fillet.png',
            ],
            [
                'title' => 'Lemon',
                'name' => 'Fresh Lemon',
                'sku' => 'INV-LMN-021',
                'unit' => 'pcs',
                'min_stock_level' => 100,
                'cost_per_unit' => 15,
                'image' => 'inventory/lemon.png',
            ],
            ['title' => 'Fettuccine Pasta', 'name' => 'Fettuccine Pasta', 'sku' => 'INV-PST-022', 'unit' => 'kg', 'min_stock_level' => 10, 'cost_per_unit' => 150, 'image' => 'inventory/fettuccine.png'],
            ['title' => 'Truffle Oil', 'name' => 'Truffle Oil', 'sku' => 'INV-TRFO-023', 'unit' => 'L', 'min_stock_level' => 2, 'cost_per_unit' => 3500, 'image' => 'inventory/truffle_oil.png'],
            ['title' => 'Guanciale', 'name' => 'Guanciale', 'sku' => 'INV-GNC-024', 'unit' => 'kg', 'min_stock_level' => 5, 'cost_per_unit' => 2200, 'image' => 'inventory/guanciale.png'],
            ['title' => 'Pecorino Romano', 'name' => 'Pecorino Romano', 'sku' => 'INV-PEC-025', 'unit' => 'kg', 'min_stock_level' => 3, 'cost_per_unit' => 1800, 'image' => 'inventory/pecorino.png'],
            ['title' => 'Farm-fresh Egg', 'name' => 'Farm-fresh Egg', 'sku' => 'INV-EGG-026', 'unit' => 'pcs', 'min_stock_level' => 50, 'cost_per_unit' => 15, 'image' => 'inventory/egg.png'],
            ['title' => 'San Marzano Tomato', 'name' => 'San Marzano Tomato', 'sku' => 'INV-SMT-027', 'unit' => 'kg', 'min_stock_level' => 20, 'cost_per_unit' => 350, 'image' => 'inventory/san_marzano.png'],
            ['title' => 'Buffalo Mozzarella', 'name' => 'Buffalo Mozzarella', 'sku' => 'INV-MOZ-028', 'unit' => 'kg', 'min_stock_level' => 15, 'cost_per_unit' => 1200, 'image' => 'inventory/mozzarella.png'],
            ['title' => 'Fresh Basil', 'name' => 'Basil', 'sku' => 'INV-BAS-029', 'unit' => 'bundle', 'min_stock_level' => 10, 'cost_per_unit' => 25, 'image' => 'inventory/basil.png'],
            ['title' => 'Extra Virgin Olive Oil', 'name' => 'Extra Virgin Olive Oil', 'sku' => 'INV-EVO-030', 'unit' => 'L', 'min_stock_level' => 5, 'cost_per_unit' => 1500, 'image' => 'inventory/olive_oil.png'],
            ['title' => 'Pizza Dough', 'name' => 'Pizza Dough', 'sku' => 'INV-DGH-031', 'unit' => 'kg', 'min_stock_level' => 20, 'cost_per_unit' => 80, 'image' => 'inventory/pizza_dough.png'],
            ['title' => 'T-Bone Steak', 'name' => 'T-Bone Steak', 'sku' => 'INV-TBN-032', 'unit' => 'kg', 'min_stock_level' => 10, 'cost_per_unit' => 2500, 'image' => 'inventory/tbone_steak.png'],
            ['title' => 'Ladyfingers', 'name' => 'Ladyfingers', 'sku' => 'INV-LDF-033', 'unit' => 'pack', 'min_stock_level' => 15, 'cost_per_unit' => 300, 'image' => 'inventory/ladyfingers.png'],
            ['title' => 'Mascarpone Cheese', 'name' => 'Mascarpone Cheese', 'sku' => 'INV-MSC-034', 'unit' => 'kg', 'min_stock_level' => 5, 'cost_per_unit' => 1600, 'image' => 'inventory/mascarpone.png'],
            ['title' => 'Espresso Coffee', 'name' => 'Espresso Coffee', 'sku' => 'INV-ESP-035', 'unit' => 'kg', 'min_stock_level' => 5, 'cost_per_unit' => 2000, 'image' => 'inventory/espresso.png'],
            ['title' => 'Cocoa Powder', 'name' => 'Cocoa Powder', 'sku' => 'INV-COC-036', 'unit' => 'kg', 'min_stock_level' => 3, 'cost_per_unit' => 800, 'image' => 'inventory/cocoa.png'],
            ['title' => 'White Sugar', 'name' => 'Sugar', 'sku' => 'INV-SGR-037', 'unit' => 'kg', 'min_stock_level' => 20, 'cost_per_unit' => 135, 'image' => 'inventory/sugar.png'],
            ['title' => 'Potato Gnocchi', 'name' => 'Potato Gnocchi', 'sku' => 'INV-GNC-038', 'unit' => 'kg', 'min_stock_level' => 10, 'cost_per_unit' => 400, 'image' => 'inventory/gnocchi.png'],
            ['title' => 'Basil Pesto', 'name' => 'Basil Pesto', 'sku' => 'INV-PST-039', 'unit' => 'jar', 'min_stock_level' => 10, 'cost_per_unit' => 450, 'image' => 'inventory/pesto.png'],
            ['title' => 'Parmesan Cheese', 'name' => 'Parmesan Cheese', 'sku' => 'INV-PRM-040', 'unit' => 'kg', 'min_stock_level' => 5, 'cost_per_unit' => 2800, 'image' => 'inventory/parmesan.png'],
            ['title' => 'Lasagna Sheets', 'name' => 'Lasagna Sheets', 'sku' => 'INV-LSG-041', 'unit' => 'pack', 'min_stock_level' => 10, 'cost_per_unit' => 250, 'image' => 'inventory/lasagna_sheets.png'],
            ['title' => 'Minced Beef', 'name' => 'Minced Beef', 'sku' => 'INV-MBF-042', 'unit' => 'kg', 'min_stock_level' => 15, 'cost_per_unit' => 850, 'image' => 'inventory/minced_beef.png'],
            ['title' => 'Bechamel Sauce', 'name' => 'Bechamel Sauce', 'sku' => 'INV-BCH-043', 'unit' => 'L', 'min_stock_level' => 5, 'cost_per_unit' => 350, 'image' => 'inventory/bechamel.png'],
            ['title' => 'Gorgonzola Cheese', 'name' => 'Gorgonzola Cheese', 'sku' => 'INV-GRG-044', 'unit' => 'kg', 'min_stock_level' => 3, 'cost_per_unit' => 2400, 'image' => 'inventory/gorgonzola.png'],
            ['title' => 'Fontina Cheese', 'name' => 'Fontina Cheese', 'sku' => 'INV-FNT-045', 'unit' => 'kg', 'min_stock_level' => 3, 'cost_per_unit' => 2200, 'image' => 'inventory/fontina.png'],
            ['title' => 'Arborio Rice', 'name' => 'Arborio Rice', 'sku' => 'INV-ARB-046', 'unit' => 'kg', 'min_stock_level' => 10, 'cost_per_unit' => 600, 'image' => 'inventory/arborio_rice.png'],
            ['title' => 'Porcini Mushrooms', 'name' => 'Porcini Mushrooms', 'sku' => 'INV-PRC-047', 'unit' => 'kg', 'min_stock_level' => 2, 'cost_per_unit' => 3500, 'image' => 'inventory/porcini.png'],
            ['title' => 'Vegetable Broth', 'name' => 'Vegetable Broth', 'sku' => 'INV-BRT-048', 'unit' => 'L', 'min_stock_level' => 15, 'cost_per_unit' => 150, 'image' => 'inventory/vegetable_broth.png'],
            ['title' => 'Rustic Bread', 'name' => 'Rustic Bread', 'sku' => 'INV-BRD-049', 'unit' => 'loaf', 'min_stock_level' => 10, 'cost_per_unit' => 120, 'image' => 'inventory/rustic_bread.png'],
            ['title' => 'Heavy Cream', 'name' => 'Heavy Cream', 'sku' => 'INV-CRM-050', 'unit' => 'L', 'min_stock_level' => 10, 'cost_per_unit' => 600, 'image' => 'inventory/heavy_cream.png'],
            ['title' => 'Gelatin', 'name' => 'Gelatin', 'sku' => 'INV-GLT-051', 'unit' => 'kg', 'min_stock_level' => 2, 'cost_per_unit' => 1200, 'image' => 'inventory/gelatin.png'],
            ['title' => 'Vanilla Extract', 'name' => 'Vanilla Extract', 'sku' => 'INV-VNL-052', 'unit' => 'bottle', 'min_stock_level' => 2, 'cost_per_unit' => 800, 'image' => 'inventory/vanilla_extract.png'],
            ['title' => 'Ricotta Cheese', 'name' => 'Ricotta Cheese', 'sku' => 'INV-RCT-053', 'unit' => 'kg', 'min_stock_level' => 5, 'cost_per_unit' => 1100, 'image' => 'inventory/ricotta.png'],
            ['title' => 'Carrot', 'name' => 'Carrot', 'sku' => 'INV-CRT-054', 'unit' => 'kg', 'min_stock_level' => 10, 'cost_per_unit' => 60, 'image' => 'inventory/carrot.png'],
            ['title' => 'Celery', 'name' => 'Celery', 'sku' => 'INV-CEL-055', 'unit' => 'kg', 'min_stock_level' => 5, 'cost_per_unit' => 150, 'image' => 'inventory/celery.png'],
            ['title' => 'Spaghetti Pasta', 'name' => 'Spaghetti', 'sku' => 'INV-SPG-056', 'unit' => 'kg', 'min_stock_level' => 15, 'cost_per_unit' => 140, 'image' => 'inventory/spaghetti.png'],
            ['title' => 'Balsamic Glaze', 'name' => 'Balsamic Glaze', 'sku' => 'INV-BLS-057', 'unit' => 'bottle', 'min_stock_level' => 2, 'cost_per_unit' => 950, 'image' => 'inventory/balsamic_glaze.png'],
            ['title' => 'Chocolate Chips', 'name' => 'Chocolate Chips', 'sku' => 'INV-CHCP-058', 'unit' => 'kg', 'min_stock_level' => 5, 'cost_per_unit' => 800, 'image' => 'inventory/chocolate_chips.png'],
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
