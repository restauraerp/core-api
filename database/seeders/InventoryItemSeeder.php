<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\Location;
use App\Support\Inventory\SellableInventory;
use Illuminate\Database\Seeder;
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

        if (! File::exists($destinationPath)) {
            File::makeDirectory($destinationPath, 0755, true);
        }

        if (File::exists($sourcePath)) {
            File::copyDirectory($sourcePath, $destinationPath);
        }

        $locations = Location::all();

        $items = [
            [
                'title' => 'Basmati Rice',
                'description' => 'Basmati Rice',
                'sku' => 'INV-RICE-001',
                'unit' => 'kg',
                'min_stock_level' => 50,
                'cost_per_unit' => 120,
                'image' => 'inventory/basmati_rice.png',
            ],
            [
                'title' => 'Chicken Breast',
                'description' => 'Chicken Breast',
                'sku' => 'INV-CHK-002',
                'unit' => 'kg',
                'min_stock_level' => 30,
                'cost_per_unit' => 250,
                'image' => 'inventory/chicken_breast.png',
            ],
            [
                'title' => 'Cooking Oil (Soyabean)',
                'description' => 'Soyabean Oil',
                'sku' => 'INV-OIL-003',
                'unit' => 'liter',
                'min_stock_level' => 20,
                'cost_per_unit' => 170,
                'image' => 'inventory/soyabean_oil.png',
            ],
            [
                'title' => 'Fresh Tomato',
                'description' => 'Tomato',
                'sku' => 'INV-VEG-004',
                'unit' => 'kg',
                'min_stock_level' => 10,
                'cost_per_unit' => 60,
                'image' => 'inventory/fresh_tomato.png',
            ],
            [
                'title' => 'Salt',
                'description' => 'Table Salt',
                'sku' => 'INV-SLT-005',
                'unit' => 'kg',
                'min_stock_level' => 5,
                'cost_per_unit' => 35,
                'image' => 'inventory/table_salt.png',
            ],
            [
                'title' => 'Red Onion',
                'description' => 'Red Onion',
                'sku' => 'INV-ONN-006',
                'unit' => 'kg',
                'min_stock_level' => 15,
                'cost_per_unit' => 45,
                'image' => 'inventory/red_onion.png',
            ],
            [
                'title' => 'Garlic',
                'description' => 'Garlic',
                'sku' => 'INV-GRL-007',
                'unit' => 'kg',
                'min_stock_level' => 10,
                'cost_per_unit' => 120,
                'image' => 'inventory/garlic.png',
            ],
            [
                'title' => 'Coriander Powder',
                'description' => 'Coriander Powder',
                'sku' => 'INV-CRP-008',
                'unit' => 'kg',
                'min_stock_level' => 5,
                'cost_per_unit' => 200,
                'image' => 'inventory/coriander_powder.png',
            ],
            [
                'title' => 'Coriander Leaves',
                'description' => 'Fresh Coriander',
                'sku' => 'INV-CRL-009',
                'unit' => 'bundle',
                'min_stock_level' => 20,
                'cost_per_unit' => 10,
                'image' => 'inventory/coriander_leaves.png',
            ],
            [
                'title' => 'Black Pepper',
                'description' => 'Whole Black Pepper',
                'sku' => 'INV-BKP-010',
                'unit' => 'kg',
                'min_stock_level' => 3,
                'cost_per_unit' => 800,
                'image' => 'inventory/black_pepper.png',
            ],
            [
                'title' => 'Ginger',
                'description' => 'Fresh Ginger',
                'sku' => 'INV-GIN-011',
                'unit' => 'kg',
                'min_stock_level' => 10,
                'cost_per_unit' => 150,
                'image' => 'inventory/ginger.png',
            ],
            [
                'title' => 'Green Chilli',
                'description' => 'Green Chilli',
                'sku' => 'INV-GCH-012',
                'unit' => 'kg',
                'min_stock_level' => 5,
                'cost_per_unit' => 80,
                'image' => 'inventory/green_chilli.png',
            ],
            [
                'title' => 'Potatoes',
                'description' => 'Potatoes',
                'sku' => 'INV-POT-013',
                'unit' => 'kg',
                'min_stock_level' => 50,
                'cost_per_unit' => 30,
                'image' => 'inventory/potatoes.png',
            ],
            [
                'title' => 'Butter',
                'description' => 'Butter Block',
                'sku' => 'INV-BTR-014',
                'unit' => 'kg',
                'min_stock_level' => 10,
                'cost_per_unit' => 600,
                'image' => 'inventory/butter.png',
            ],
            [
                'title' => 'Milk',
                'description' => 'Fresh Milk',
                'sku' => 'INV-MLK-015',
                'unit' => 'liter',
                'min_stock_level' => 20,
                'cost_per_unit' => 90,
                'image' => 'inventory/milk.png',
            ],
            [
                'title' => 'Ravioli Pasta',
                'description' => 'Fresh Ravioli',
                'sku' => 'INV-RAV-016',
                'unit' => 'kg',
                'min_stock_level' => 5,
                'cost_per_unit' => 450,
                'image' => 'inventory/ravioli_pasta.png',
            ],
            [
                'title' => 'Truffle Paste',
                'description' => 'Black Truffle Paste',
                'sku' => 'INV-TRF-017',
                'unit' => 'jar',
                'min_stock_level' => 5,
                'cost_per_unit' => 1200,
                'image' => 'inventory/truffle_paste.png',
            ],
            [
                'title' => 'Spicy Salami',
                'description' => 'Spicy Italian Salami',
                'sku' => 'INV-SAL-018',
                'unit' => 'kg',
                'min_stock_level' => 10,
                'cost_per_unit' => 1800,
                'image' => 'inventory/spicy_salami.png',
            ],
            [
                'title' => 'Chili Flakes',
                'description' => 'Red Chili Flakes',
                'sku' => 'INV-CHL-019',
                'unit' => 'kg',
                'min_stock_level' => 2,
                'cost_per_unit' => 400,
                'image' => 'inventory/chili_flakes.png',
            ],
            [
                'title' => 'Salmon Fillet',
                'description' => 'Fresh Salmon Fillet',
                'sku' => 'INV-SLM-020',
                'unit' => 'kg',
                'min_stock_level' => 10,
                'cost_per_unit' => 2500,
                'image' => 'inventory/salmon_fillet.png',
            ],
            [
                'title' => 'Lemon',
                'description' => 'Fresh Lemon',
                'sku' => 'INV-LMN-021',
                'unit' => 'pcs',
                'min_stock_level' => 100,
                'cost_per_unit' => 15,
                'image' => 'inventory/lemon.png',
            ],
            ['title' => 'Fettuccine Pasta', 'description' => 'Fettuccine Pasta', 'sku' => 'INV-PST-022', 'unit' => 'kg', 'min_stock_level' => 10, 'cost_per_unit' => 150, 'image' => 'inventory/fettuccine.png'],
            ['title' => 'Truffle Oil', 'description' => 'Truffle Oil', 'sku' => 'INV-TRFO-023', 'unit' => 'L', 'min_stock_level' => 2, 'cost_per_unit' => 3500, 'image' => 'inventory/truffle_oil.png'],
            ['title' => 'Guanciale', 'description' => 'Guanciale', 'sku' => 'INV-GNC-024', 'unit' => 'kg', 'min_stock_level' => 5, 'cost_per_unit' => 2200, 'image' => 'inventory/guanciale.png'],
            ['title' => 'Pecorino Romano', 'description' => 'Pecorino Romano', 'sku' => 'INV-PEC-025', 'unit' => 'kg', 'min_stock_level' => 3, 'cost_per_unit' => 1800, 'image' => 'inventory/pecorino.png'],
            ['title' => 'Farm-fresh Egg', 'description' => 'Farm-fresh Egg', 'sku' => 'INV-EGG-026', 'unit' => 'pcs', 'min_stock_level' => 50, 'cost_per_unit' => 15, 'image' => 'inventory/egg.png'],
            ['title' => 'San Marzano Tomato', 'description' => 'San Marzano Tomato', 'sku' => 'INV-SMT-027', 'unit' => 'kg', 'min_stock_level' => 20, 'cost_per_unit' => 350, 'image' => 'inventory/san_marzano.png'],
            ['title' => 'Buffalo Mozzarella', 'description' => 'Buffalo Mozzarella', 'sku' => 'INV-MOZ-028', 'unit' => 'kg', 'min_stock_level' => 15, 'cost_per_unit' => 1200, 'image' => 'inventory/mozzarella.png'],
            ['title' => 'Fresh Basil', 'description' => 'Basil', 'sku' => 'INV-BAS-029', 'unit' => 'bundle', 'min_stock_level' => 10, 'cost_per_unit' => 25, 'image' => 'inventory/basil.png'],
            ['title' => 'Extra Virgin Olive Oil', 'description' => 'Extra Virgin Olive Oil', 'sku' => 'INV-EVO-030', 'unit' => 'L', 'min_stock_level' => 5, 'cost_per_unit' => 1500, 'image' => 'inventory/olive_oil.png'],
            ['title' => 'Pizza Dough', 'description' => 'Pizza Dough', 'sku' => 'INV-DGH-031', 'unit' => 'kg', 'min_stock_level' => 20, 'cost_per_unit' => 80, 'image' => 'inventory/pizza_dough.png'],
            ['title' => 'T-Bone Steak', 'description' => 'T-Bone Steak', 'sku' => 'INV-TBN-032', 'unit' => 'kg', 'min_stock_level' => 10, 'cost_per_unit' => 2500, 'image' => 'inventory/tbone_steak.png'],
            ['title' => 'Ladyfingers', 'description' => 'Ladyfingers', 'sku' => 'INV-LDF-033', 'unit' => 'pack', 'min_stock_level' => 15, 'cost_per_unit' => 300, 'image' => 'inventory/ladyfingers.png'],
            ['title' => 'Mascarpone Cheese', 'description' => 'Mascarpone Cheese', 'sku' => 'INV-MSC-034', 'unit' => 'kg', 'min_stock_level' => 5, 'cost_per_unit' => 1600, 'image' => 'inventory/mascarpone.png'],
            ['title' => 'Espresso Coffee', 'description' => 'Espresso Coffee', 'sku' => 'INV-ESP-035', 'unit' => 'kg', 'min_stock_level' => 5, 'cost_per_unit' => 2000, 'image' => 'inventory/espresso.png'],
            ['title' => 'Cocoa Powder', 'description' => 'Cocoa Powder', 'sku' => 'INV-COC-036', 'unit' => 'kg', 'min_stock_level' => 3, 'cost_per_unit' => 800, 'image' => 'inventory/cocoa.png'],
            ['title' => 'White Sugar', 'description' => 'Sugar', 'sku' => 'INV-SGR-037', 'unit' => 'kg', 'min_stock_level' => 20, 'cost_per_unit' => 135, 'image' => 'inventory/sugar.png'],
            ['title' => 'Potato Gnocchi', 'description' => 'Potato Gnocchi', 'sku' => 'INV-GNC-038', 'unit' => 'kg', 'min_stock_level' => 10, 'cost_per_unit' => 400, 'image' => 'inventory/gnocchi.png'],
            ['title' => 'Basil Pesto', 'description' => 'Basil Pesto', 'sku' => 'INV-PST-039', 'unit' => 'jar', 'min_stock_level' => 10, 'cost_per_unit' => 450, 'image' => 'inventory/pesto.png'],
            ['title' => 'Parmesan Cheese', 'description' => 'Parmesan Cheese', 'sku' => 'INV-PRM-040', 'unit' => 'kg', 'min_stock_level' => 5, 'cost_per_unit' => 2800, 'image' => 'inventory/parmesan.png'],
            ['title' => 'Lasagna Sheets', 'description' => 'Lasagna Sheets', 'sku' => 'INV-LSG-041', 'unit' => 'pack', 'min_stock_level' => 10, 'cost_per_unit' => 250, 'image' => 'inventory/lasagna_sheets.png'],
            ['title' => 'Minced Beef', 'description' => 'Minced Beef', 'sku' => 'INV-MBF-042', 'unit' => 'kg', 'min_stock_level' => 15, 'cost_per_unit' => 850, 'image' => 'inventory/minced_beef.png'],
            ['title' => 'Bechamel Sauce', 'description' => 'Bechamel Sauce', 'sku' => 'INV-BCH-043', 'unit' => 'L', 'min_stock_level' => 5, 'cost_per_unit' => 350, 'image' => 'inventory/bechamel.png'],
            ['title' => 'Gorgonzola Cheese', 'description' => 'Gorgonzola Cheese', 'sku' => 'INV-GRG-044', 'unit' => 'kg', 'min_stock_level' => 3, 'cost_per_unit' => 2400, 'image' => 'inventory/gorgonzola.png'],
            ['title' => 'Fontina Cheese', 'description' => 'Fontina Cheese', 'sku' => 'INV-FNT-045', 'unit' => 'kg', 'min_stock_level' => 3, 'cost_per_unit' => 2200, 'image' => 'inventory/fontina.png'],
            ['title' => 'Arborio Rice', 'description' => 'Arborio Rice', 'sku' => 'INV-ARB-046', 'unit' => 'kg', 'min_stock_level' => 10, 'cost_per_unit' => 600, 'image' => 'inventory/arborio_rice.png'],
            ['title' => 'Porcini Mushrooms', 'description' => 'Porcini Mushrooms', 'sku' => 'INV-PRC-047', 'unit' => 'kg', 'min_stock_level' => 2, 'cost_per_unit' => 3500, 'image' => 'inventory/porcini.png'],
            ['title' => 'Vegetable Broth', 'description' => 'Vegetable Broth', 'sku' => 'INV-BRT-048', 'unit' => 'L', 'min_stock_level' => 15, 'cost_per_unit' => 150, 'image' => 'inventory/vegetable_broth.png'],
            ['title' => 'Rustic Bread', 'description' => 'Rustic Bread', 'sku' => 'INV-BRD-049', 'unit' => 'loaf', 'min_stock_level' => 10, 'cost_per_unit' => 120, 'image' => 'inventory/rustic_bread.png'],
            ['title' => 'Heavy Cream', 'description' => 'Heavy Cream', 'sku' => 'INV-CRM-050', 'unit' => 'L', 'min_stock_level' => 10, 'cost_per_unit' => 600, 'image' => 'inventory/heavy_cream.png'],
            ['title' => 'Gelatin', 'description' => 'Gelatin', 'sku' => 'INV-GLT-051', 'unit' => 'kg', 'min_stock_level' => 2, 'cost_per_unit' => 1200, 'image' => 'inventory/gelatin.png'],
            ['title' => 'Vanilla Extract', 'description' => 'Vanilla Extract', 'sku' => 'INV-VNL-052', 'unit' => 'bottle', 'min_stock_level' => 2, 'cost_per_unit' => 800, 'image' => 'inventory/vanilla_extract.png'],
            ['title' => 'Ricotta Cheese', 'description' => 'Ricotta Cheese', 'sku' => 'INV-RCT-053', 'unit' => 'kg', 'min_stock_level' => 5, 'cost_per_unit' => 1100, 'image' => 'inventory/ricotta.png'],
            ['title' => 'Carrot', 'description' => 'Carrot', 'sku' => 'INV-CRT-054', 'unit' => 'kg', 'min_stock_level' => 10, 'cost_per_unit' => 60, 'image' => 'inventory/carrot.png'],
            ['title' => 'Celery', 'description' => 'Celery', 'sku' => 'INV-CEL-055', 'unit' => 'kg', 'min_stock_level' => 5, 'cost_per_unit' => 150, 'image' => 'inventory/celery.png'],
            ['title' => 'Spaghetti Pasta', 'description' => 'Spaghetti', 'sku' => 'INV-SPG-056', 'unit' => 'kg', 'min_stock_level' => 15, 'cost_per_unit' => 140, 'image' => 'inventory/spaghetti.png'],
            ['title' => 'Balsamic Glaze', 'description' => 'Balsamic Glaze', 'sku' => 'INV-BLS-057', 'unit' => 'bottle', 'min_stock_level' => 2, 'cost_per_unit' => 950, 'image' => 'inventory/balsamic_glaze.png'],
            ['title' => 'Chocolate Chips', 'description' => 'Chocolate Chips', 'sku' => 'INV-CHCP-058', 'unit' => 'kg', 'min_stock_level' => 5, 'cost_per_unit' => 800, 'image' => 'inventory/chocolate_chips.png'],

            // Sold exactly as bought: these reach the till as products of their
            // own, and every sale takes one off the shelf. See
            // App\Support\Inventory\SellableInventory.
            ['title' => 'Mineral Water 500ml', 'description' => 'Chilled bottled water, sold by the bottle.', 'sku' => 'INV-WTR-059', 'unit' => 'bottle', 'min_stock_level' => 48, 'cost_per_unit' => 15, 'is_sellable' => true, 'selling_price' => 25],
            ['title' => 'Cola Can 330ml', 'description' => 'Canned soft drink, sold by the can.', 'sku' => 'INV-COLA-060', 'unit' => 'can', 'min_stock_level' => 36, 'cost_per_unit' => 35, 'is_sellable' => true, 'selling_price' => 60],
            ['title' => 'Potato Crisps 40g', 'description' => 'Packet crisps from the counter display.', 'sku' => 'INV-CRSP-061', 'unit' => 'packet', 'min_stock_level' => 24, 'cost_per_unit' => 20, 'is_sellable' => true, 'selling_price' => 40],
        ];

        foreach ($items as $itemData) {
            $item = InventoryItem::firstOrCreate(
                ['sku' => $itemData['sku']],
                $itemData
            );

            // Every outlet stocks every item, starting empty. The levels are
            // not invented here: purchase orders are what put goods in a
            // restaurant, so OrderSeeder sets them from the deliveries it
            // writes, exactly as the API does for a real one.
            if ($locations->count() > 0) {
                $syncData = [];
                foreach ($locations as $loc) {
                    $syncData[$loc->id] = [
                        'quantity' => 0,
                        'is_active' => true,
                    ];
                }
                $item->locations()->sync($syncData);
            }

            $item->forceFill(['current_stock' => 0])->save();

            // Items sold as bought need their till-facing product, and it can
            // only be built once the outlets are known.
            app(SellableInventory::class)->sync($item);
        }
    }
}
