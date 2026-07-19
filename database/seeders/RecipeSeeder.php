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
        $ingredients = InventoryItem::all()->keyBy('name');

        // Helper function
        $createRecipe = function($productName, $recipeData) use ($ingredients) {
            $product = Product::where('name', $productName)->first();
            if ($product) {
                foreach ($recipeData as $ingredientName => $qty) {
                    if (isset($ingredients[$ingredientName])) {
                        Recipe::firstOrCreate([
                            'product_id' => $product->id,
                            'inventory_item_id' => $ingredients[$ingredientName]->id,
                        ], [
                            'quantity_required' => $qty
                        ]);
                    }
                }
            }
        };

        // 1. Truffle Carbonara
        $createRecipe('Truffle Carbonara', [
            'Fettuccine Pasta' => 0.2,
            'Guanciale' => 0.05,
            'Pecorino Romano' => 0.03,
            'Farm-fresh Egg' => 2,
            'Truffle Oil' => 0.01,
        ]);

        // 2. Margherita Verace
        $createRecipe('Margherita Verace', [
            'Pizza Dough' => 0.25,
            'San Marzano Tomato' => 0.1,
            'Buffalo Mozzarella' => 0.15,
            'Basil' => 0.2,
            'Extra Virgin Olive Oil' => 0.02,
        ]);

        // 3. Bistecca alla Fiorentina
        $createRecipe('Bistecca alla Fiorentina', [
            'T-Bone Steak' => 0.8, // 800g
            'Extra Virgin Olive Oil' => 0.03,
            'Table Salt' => 0.01,
            'Whole Black Pepper' => 0.005,
        ]);

        // 4. Classic Tiramisu
        $createRecipe('Classic Tiramisu', [
            'Ladyfingers' => 1, // 1 pack
            'Mascarpone Cheese' => 0.15,
            'Espresso Coffee' => 0.05,
            'Cocoa Powder' => 0.01,
            'Sugar' => 0.05,
            'Farm-fresh Egg' => 1,
        ]);

        // 5. Gnocchi al Pesto
        $createRecipe('Gnocchi al Pesto', [
            'Potato Gnocchi' => 0.2,
            'Basil Pesto' => 0.05,
            'Parmesan Cheese' => 0.03,
            'Extra Virgin Olive Oil' => 0.01,
        ]);

        // 6. Lasagna al Forno
        $createRecipe('Lasagna al Forno', [
            'Lasagna Sheets' => 0.5, // half pack
            'Minced Beef' => 0.15,
            'San Marzano Tomato' => 0.1,
            'Bechamel Sauce' => 0.1,
            'Buffalo Mozzarella' => 0.1,
            'Parmesan Cheese' => 0.03,
        ]);

        // 7. Pizza Quattro Formaggi
        $createRecipe('Pizza Quattro Formaggi', [
            'Pizza Dough' => 0.25,
            'Buffalo Mozzarella' => 0.1,
            'Gorgonzola Cheese' => 0.05,
            'Parmesan Cheese' => 0.03,
            'Fontina Cheese' => 0.05,
        ]);

        // 8. Risotto ai Funghi
        $createRecipe('Risotto ai Funghi', [
            'Arborio Rice' => 0.15,
            'Porcini Mushrooms' => 0.05,
            'Vegetable Broth' => 0.3, // 300ml
            'Butter Block' => 0.02,
            'Parmesan Cheese' => 0.03,
        ]);

        // 9. Bruschetta al Pomodoro
        $createRecipe('Bruschetta al Pomodoro', [
            'Rustic Bread' => 0.2, // 0.2 loaf
            'Tomato' => 0.1,
            'Garlic' => 0.01,
            'Basil' => 0.1,
            'Extra Virgin Olive Oil' => 0.02,
        ]);

        // 10. Panna Cotta
        $createRecipe('Panna Cotta', [
            'Heavy Cream' => 0.15,
            'Sugar' => 0.03,
            'Gelatin' => 0.01,
            'Vanilla Extract' => 0.005,
        ]);

        // 11. Calzone Classico
        $createRecipe('Calzone Classico', [
            'Pizza Dough' => 0.25,
            'Ricotta Cheese' => 0.1,
            'Spicy Italian Salami' => 0.05,
            'San Marzano Tomato' => 0.05,
            'Buffalo Mozzarella' => 0.1,
        ]);

        // 12. Spaghetti Bolognese
        $createRecipe('Spaghetti Bolognese', [
            'Spaghetti' => 0.15,
            'Minced Beef' => 0.15,
            'San Marzano Tomato' => 0.1,
            'Red Onion' => 0.02,
            'Carrot' => 0.02,
            'Celery' => 0.02,
            'Garlic' => 0.01,
        ]);

        // 13. Fettuccine Alfredo
        $createRecipe('Fettuccine Alfredo', [
            'Fettuccine Pasta' => 0.15,
            'Butter Block' => 0.03,
            'Heavy Cream' => 0.1,
            'Parmesan Cheese' => 0.05,
        ]);

        // 14. Minestrone Soup
        $createRecipe('Minestrone Soup', [
            'Carrot' => 0.05,
            'Potatoes' => 0.05,
            'Celery' => 0.03,
            'Vegetable Broth' => 0.3,
            'San Marzano Tomato' => 0.05,
        ]);

        // 15. Caprese Salad
        $createRecipe('Caprese Salad', [
            'Tomato' => 0.15,
            'Buffalo Mozzarella' => 0.15,
            'Basil' => 0.2,
            'Extra Virgin Olive Oil' => 0.02,
            'Balsamic Glaze' => 0.01,
        ]);

        // 16. Gelato Stracciatella
        $createRecipe('Gelato Stracciatella', [
            'Fresh Milk' => 0.1,
            'Heavy Cream' => 0.1,
            'Sugar' => 0.05,
            'Chocolate Chips' => 0.05,
        ]);

        // 17. Ravioli al Tartufo
        $createRecipe('Ravioli al Tartufo', [
            'Fresh Ravioli' => 0.2,
            'Black Truffle Paste' => 0.05,
            'Pecorino Romano' => 0.02,
        ]);

        // 18. Pizza Diavola
        $createRecipe('Pizza Diavola', [
            'Pizza Dough' => 0.25,
            'San Marzano Tomato' => 0.1,
            'Buffalo Mozzarella' => 0.15,
            'Spicy Italian Salami' => 0.1,
            'Red Chili Flakes' => 0.005,
        ]);

        // 19. Salmon al Forno
        $createRecipe('Salmon al Forno', [
            'Fresh Salmon Fillet' => 0.2,
            'Extra Virgin Olive Oil' => 0.02,
            'Fresh Lemon' => 1,
        ]);

        $this->command->info('✅ RecipeSeeder: Seeded demo inventory items and linked them as recipes to all 19 products.');
    }
}
