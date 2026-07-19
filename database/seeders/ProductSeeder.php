<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ProductCategory;
use App\Models\Product;
use App\Models\Image;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // 0. Copy demo images to storage
        $sourcePath = database_path('seeders/images/foods');
        $destinationPath = storage_path('app/public/foods');

        if (!File::exists($destinationPath)) {
            File::makeDirectory($destinationPath, 0755, true);
        }

        if (File::exists($sourcePath)) {
            File::copyDirectory($sourcePath, $destinationPath);
        }
        // 1. Find Existing Categories
        $pastaCategory = ProductCategory::where('name', 'Pasta')->first() ?? ProductCategory::firstOrCreate(['name' => 'Pasta', 'slug' => 'pasta']);
        $pizzaCategory = ProductCategory::where('name', 'Pizza')->first() ?? ProductCategory::firstOrCreate(['name' => 'Pizza', 'slug' => 'pizza']);
        $grillCategory = ProductCategory::where('name', 'Grill')->first() ?? ProductCategory::firstOrCreate(['name' => 'Grill', 'slug' => 'grill']);
        $dessertCategory = ProductCategory::where('name', 'Desserts')->first() ?? ProductCategory::firstOrCreate(['name' => 'Desserts', 'slug' => 'desserts']);

        // 2. Create Products
        $productsData = [
            [
                'category_id' => $pastaCategory->id,
                'name' => 'Truffle Carbonara',
                'description' => 'Handmade fettuccine, guanciale, pecorino romano, farm-fresh egg yolk, and shaved black truffle.',
                'price' => 850,
                'sale_price' => 750,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/truffle_carbonara.png'
            ],
            [
                'category_id' => $pizzaCategory->id,
                'name' => 'Margherita Verace',
                'description' => 'San Marzano tomato sauce, fresh buffalo mozzarella, basil, and a drizzle of extra virgin olive oil.',
                'price' => 650,
                'sale_price' => 550,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/margherita_verace.png'
            ],
            [
                'category_id' => $grillCategory->id,
                'name' => 'Bistecca alla Fiorentina',
                'description' => 'Premium dry-aged T-bone steak, grilled over charcoal, served with roasted rosemary potatoes.',
                'price' => 3200,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/bistecca_fiorentina.png'
            ],
            [
                'category_id' => $dessertCategory->id,
                'name' => 'Classic Tiramisu',
                'description' => 'Layers of espresso-soaked ladyfingers, rich mascarpone cream, and dusted with premium cocoa powder.',
                'price' => 450,
                'sale_price' => 400,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/classic_tiramisu.png'
            ],
            [
                'category_id' => $pastaCategory->id,
                'name' => 'Gnocchi al Pesto',
                'description' => 'Soft potato dumplings tossed in a vibrant basil pesto with toasted pine nuts and parmesan.',
                'price' => 750,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/gnocchi_pesto.png'
            ],
            [
                'category_id' => $pastaCategory->id,
                'name' => 'Lasagna al Forno',
                'description' => 'Classic baked lasagna with rich slow-cooked beef ragu, creamy bechamel, and melted mozzarella.',
                'price' => 850,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/lasagna.png'
            ],
            [
                'category_id' => $pizzaCategory->id,
                'name' => 'Pizza Quattro Formaggi',
                'description' => 'Wood-fired white pizza topped with a decadent blend of mozzarella, gorgonzola, parmesan, and provolone.',
                'price' => 950,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/quattro_formaggi.png'
            ],
            [
                'category_id' => $pastaCategory->id,
                'name' => 'Risotto ai Funghi',
                'description' => 'Creamy Arborio rice slowly cooked with wild porcini mushrooms, finished with a drizzle of truffle oil.',
                'price' => 800,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/risotto_funghi.png'
            ],
            [
                'category_id' => $grillCategory->id, // Re-using grill for starters for simplicity, or we can just use a new one but let's keep it in existing categories or add to pasta
                'name' => 'Bruschetta al Pomodoro',
                'description' => 'Toasted artisan bread rubbed with garlic and topped with fresh diced tomatoes, basil, and balsamic glaze.',
                'price' => 350,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/bruschetta.png'
            ],
            [
                'category_id' => $dessertCategory->id,
                'name' => 'Panna Cotta',
                'description' => 'Silky vanilla bean panna cotta served with a vibrant mixed berry compote.',
                'price' => 400,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/panna_cotta.png'
            ],
            [
                'category_id' => $pizzaCategory->id,
                'name' => 'Calzone Classico',
                'description' => 'A folded pizza pocket stuffed with mozzarella, ricotta, and premium pepperoni, baked until golden.',
                'price' => 750,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/calzone.png'
            ],
            [
                'category_id' => $pastaCategory->id,
                'name' => 'Spaghetti Bolognese',
                'description' => 'Perfectly al dente spaghetti topped with our signature slow-simmered beef and tomato ragu.',
                'price' => 650,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/spaghetti_bolognese.png'
            ],
            [
                'category_id' => $pastaCategory->id,
                'name' => 'Fettuccine Alfredo',
                'description' => 'Fresh fettuccine ribbons tossed in a rich, velvety sauce of butter and aged parmesan cheese.',
                'price' => 700,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/fettuccine_alfredo.png'
            ],
            [
                'category_id' => $grillCategory->id,
                'name' => 'Minestrone Soup',
                'description' => 'A hearty traditional vegetable soup with beans, pasta, and fresh seasonal greens, served with crusty bread.',
                'price' => 350,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/minestrone.png'
            ],
            [
                'category_id' => $grillCategory->id,
                'name' => 'Caprese Salad',
                'description' => 'Fresh slices of buffalo mozzarella, ripe tomatoes, and sweet basil, drizzled with olive oil and balsamic glaze.',
                'price' => 550,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/caprese_salad.png'
            ],
            [
                'category_id' => $dessertCategory->id,
                'name' => 'Gelato Stracciatella',
                'description' => 'Classic artisanal vanilla gelato folded with fine shards of dark Italian chocolate.',
                'price' => 250,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/gelato.png'
            ],
            [
                'category_id' => $pastaCategory->id,
                'name' => 'Ravioli al Tartufo',
                'description' => 'Fresh handmade ravioli stuffed with ricotta, served in a rich black truffle cream sauce.',
                'price' => 850,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/ravioli.png'
            ],
            [
                'category_id' => $pizzaCategory->id,
                'name' => 'Pizza Diavola',
                'description' => 'Wood-fired crust with San Marzano tomato sauce, mozzarella, spicy Italian salami, and chili flakes.',
                'price' => 800,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/pizza_diavola.png'
            ],
            [
                'category_id' => $grillCategory->id,
                'name' => 'Salmon al Forno',
                'description' => 'Oven-baked fresh salmon fillet with herbs and lemon, served with roasted seasonal vegetables.',
                'price' => 1800,
                'type' => 'Standard',
                'is_active' => true,
                'image' => 'foods/salmon_al_forno.png'
            ]
        ];

        foreach ($productsData as $data) {
            $product = Product::updateOrCreate(
                ['name' => $data['name']],
                [
                    'category_id' => $data['category_id'],
                    'description' => $data['description'],
                    'price' => $data['price'],
                    'sale_price' => $data['sale_price'] ?? null,
                    'type' => $data['type'],
                    'is_active' => $data['is_active'],
                ]
            );

            // Add Product Media
            Image::updateOrCreate(
                ['imageable_id' => $product->id, 'imageable_type' => Product::class],
                [
                    'url' => $data['image'],
                    'type' => 'image',
                ]
            );
        }

        $this->command->info('✅ ProductSeeder: Seeded categories, products, and linked generated food images.');
    }
}
