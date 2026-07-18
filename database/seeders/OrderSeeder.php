<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Table;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $products = Product::all();
        $customers = Customer::all();
        $tables = Table::all();
        $locations = \App\Models\Location::all();

        if ($products->isEmpty() || $customers->isEmpty() || $tables->isEmpty()) {
            $this->command->warn('Ensure Products, Customers, and Tables are seeded before Orders.');
            return;
        }

        $orderTypes = ['dine_in', 'takeaway', 'delivery', 'catering'];

        foreach ($locations as $location) {
            $locationTables = $tables->where('location_id', $location->id);
            $numActive = rand(5, 10);
            $numCompleted = rand(3, 5);

            // Generate Active Orders
            for ($i = 0; $i < $numActive; $i++) {
                $this->createRandomOrder($location, $locationTables, $products, $customers, $orderTypes, false, now());
            }

            // Generate Completed Orders for today
            for ($i = 0; $i < $numCompleted; $i++) {
                $this->createRandomOrder($location, $locationTables, $products, $customers, $orderTypes, true, now());
            }

            // Generate 1 year of Historical Orders
            for ($daysBack = 365; $daysBack > 0; $daysBack--) {
                // Generate 1-3 orders per day per location
                $numOrdersToday = rand(1, 3);
                for ($i = 0; $i < $numOrdersToday; $i++) {
                    $date = now()->subDays($daysBack)->addHours(rand(10, 22));
                    $this->createRandomOrder($location, $locationTables, $products, $customers, $orderTypes, true, $date);
                }
            }
        }
    }

    private function createRandomOrder($location, $locationTables, $products, $customers, $orderTypes, $isCompleted, $date = null)
    {
        $date = $date ?? now();
        $type = $orderTypes[array_rand($orderTypes)];
        
        $validStatuses = [];
        $paymentStatus = 'unpaid';
        
        if ($isCompleted) {
            $paymentStatus = 'paid';
            if ($type === 'dine_in') {
                $status = 'served';
            } elseif ($type === 'takeaway') {
                $status = 'packed';
            } else {
                $status = 'delivered';
            }
        } else {
            if ($type === 'dine_in') {
                $validStatuses = ['pending', 'cooking', 'cooked', 'served'];
            } elseif ($type === 'takeaway') {
                $validStatuses = ['pending', 'cooking', 'cooked', 'packed'];
            } else { 
                $validStatuses = ['pending', 'cooking', 'cooked', 'packed', 'picked', 'delivered'];
            }
            $status = $validStatuses[array_rand($validStatuses)];
        }

        $customer = $customers->random();

        $subtotal = rand(500, 5000);
        $tax = $subtotal * 0.1;
        $deliveryCharge = in_array($type, ['delivery', 'catering']) ? rand(50, 150) : 0;
        $total = $subtotal + $tax + $deliveryCharge;

        $order = Order::create([
            'location_id' => $location->id,
            'user_id' => 1,
            'customer_id' => $customer->id,
            'table_id' => ($type === 'dine_in' && $locationTables->isNotEmpty()) ? $locationTables->random()->id : null,
            'order_type' => $type,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'discount_amount' => 0,
            'delivery_charge' => $deliveryCharge,
            'total' => $total,
            'delivery_time' => in_array($type, ['takeaway', 'delivery', 'catering']) ? (clone $date)->addHours(rand(1, 48)) : null,
            'delivery_address' => in_array($type, ['delivery', 'catering']) ? $customer->address ?? '123 Test Ave, Dhaka' : null,
            'latitude' => in_array($type, ['delivery', 'catering']) ? (23.8103 + (rand(-100, 100) / 10000)) : null,
            'longitude' => in_array($type, ['delivery', 'catering']) ? (90.4125 + (rand(-100, 100) / 10000)) : null,
        ]);
        
        $order->timestamps = false;
        $order->update([
            'created_at' => $date,
            'updated_at' => $date,
        ]);
        $order->timestamps = true;

        $itemCount = rand(2, 4);
        for ($j = 0; $j < $itemCount; $j++) {
            $product = $products->random();
            $qty = rand(1, 3);
            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => $qty,
                'price' => $product->price,
            ]);
        }

        if ($paymentStatus === 'paid') {
            $order->payments()->create([
                'method' => ['cash', 'card', 'mfs'][array_rand(['cash', 'card', 'mfs'])],
                'amount' => $total,
                'status' => 'completed',
            ]);
        }
    }
}
