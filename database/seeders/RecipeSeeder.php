<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\InventoryItem;
use App\Models\Recipe;

class RecipeSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Inventory Items (Ingredients)
        $ingredients = [
            ['name' => 'Fettuccine Pasta', 'unit' => 'kg', 'min_stock_level' => 10],
            ['name' => 'Truffle Oil', 'unit' => 'L', 'min_stock_level' => 2],
            ['name' => 'Guanciale', 'unit' => 'kg', 'min_stock_level' => 5],
            ['name' => 'Pecorino Romano', 'unit' => 'kg', 'min_stock_level' => 3],
            ['name' => 'Farm-fresh Egg', 'unit' => 'pcs', 'min_stock_level' => 50],
            ['name' => 'San Marzano Tomato', 'unit' => 'kg', 'min_stock_level' => 20],
            ['name' => 'Buffalo Mozzarella', 'unit' => 'kg', 'min_stock_level' => 15],
            ['name' => 'Basil', 'unit' => 'bunch', 'min_stock_level' => 10],
            ['name' => 'Extra Virgin Olive Oil', 'unit' => 'L', 'min_stock_level' => 5],
            ['name' => 'Pizza Dough', 'unit' => 'kg', 'min_stock_level' => 20],
            ['name' => 'Fresh Ravioli', 'unit' => 'kg', 'min_stock_level' => 5],
            ['name' => 'Black Truffle Paste', 'unit' => 'jar', 'min_stock_level' => 5],
            ['name' => 'Spicy Italian Salami', 'unit' => 'kg', 'min_stock_level' => 10],
            ['name' => 'Red Chili Flakes', 'unit' => 'kg', 'min_stock_level' => 2],
            ['name' => 'Fresh Salmon Fillet', 'unit' => 'kg', 'min_stock_level' => 10],
            ['name' => 'Fresh Lemon', 'unit' => 'pcs', 'min_stock_level' => 100],
        ];

        $createdIngredients = [];
        foreach ($ingredients as $item) {
            $createdIngredients[$item['name']] = InventoryItem::firstOrCreate(
                ['name' => $item['name']],
                ['unit' => $item['unit'], 'min_stock_level' => $item['min_stock_level']]
            );
        }

        // 2. Map to Recipes
        // Truffle Carbonara
        $truffleCarbonara = Product::where('name', 'Truffle Carbonara')->first();
        if ($truffleCarbonara) {
            $this->createRecipe($truffleCarbonara->id, $createdIngredients['Fettuccine Pasta']->id, 0.2); // 200g
            $this->createRecipe($truffleCarbonara->id, $createdIngredients['Guanciale']->id, 0.05); // 50g
            $this->createRecipe($truffleCarbonara->id, $createdIngredients['Pecorino Romano']->id, 0.03); // 30g
            $this->createRecipe($truffleCarbonara->id, $createdIngredients['Farm-fresh Egg']->id, 2); // 2 eggs
            $this->createRecipe($truffleCarbonara->id, $createdIngredients['Truffle Oil']->id, 0.01); // 10ml
        }

        // Margherita Verace
        $margherita = Product::where('name', 'Margherita Verace')->first();
        if ($margherita) {
            $this->createRecipe($margherita->id, $createdIngredients['Pizza Dough']->id, 0.25); // 250g
            $this->createRecipe($margherita->id, $createdIngredients['San Marzano Tomato']->id, 0.1); // 100g
            $this->createRecipe($margherita->id, $createdIngredients['Buffalo Mozzarella']->id, 0.15); // 150g
            $this->createRecipe($margherita->id, $createdIngredients['Basil']->id, 0.2); // some basil
            $this->createRecipe($margherita->id, $createdIngredients['Extra Virgin Olive Oil']->id, 0.02); // 20ml
        }

        // Ravioli al Tartufo
        $ravioli = Product::where('name', 'Ravioli al Tartufo')->first();
        if ($ravioli) {
            $this->createRecipe($ravioli->id, $createdIngredients['Fresh Ravioli']->id, 0.2); // 200g
            $this->createRecipe($ravioli->id, $createdIngredients['Black Truffle Paste']->id, 0.05); // 0.05 jar (50g)
            $this->createRecipe($ravioli->id, $createdIngredients['Pecorino Romano']->id, 0.02); // 20g
        }

        // Pizza Diavola
        $diavola = Product::where('name', 'Pizza Diavola')->first();
        if ($diavola) {
            $this->createRecipe($diavola->id, $createdIngredients['Pizza Dough']->id, 0.25); // 250g
            $this->createRecipe($diavola->id, $createdIngredients['San Marzano Tomato']->id, 0.1); // 100g
            $this->createRecipe($diavola->id, $createdIngredients['Buffalo Mozzarella']->id, 0.15); // 150g
            $this->createRecipe($diavola->id, $createdIngredients['Spicy Italian Salami']->id, 0.1); // 100g
            $this->createRecipe($diavola->id, $createdIngredients['Red Chili Flakes']->id, 0.005); // 5g
        }

        // Salmon al Forno
        $salmon = Product::where('name', 'Salmon al Forno')->first();
        if ($salmon) {
            $this->createRecipe($salmon->id, $createdIngredients['Fresh Salmon Fillet']->id, 0.2); // 200g
            $this->createRecipe($salmon->id, $createdIngredients['Extra Virgin Olive Oil']->id, 0.02); // 20ml
            $this->createRecipe($salmon->id, $createdIngredients['Fresh Lemon']->id, 1); // 1 pc
        }

        $this->command->info('✅ RecipeSeeder: Seeded demo inventory items and linked them as recipes to products.');
    }

    private function createRecipe($productId, $inventoryItemId, $qty)
    {
        Recipe::firstOrCreate([
            'product_id' => $productId,
            'inventory_item_id' => $inventoryItemId,
        ], [
            'quantity_required' => $qty
        ]);
    }
}
