<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Table;
use Carbon\Carbon;

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

        foreach ($locations as $location) {
            $locationTables = $tables->where('location_id', $location->id);
            $numActive = rand(5, 10);
            $numCompleted = rand(3, 5);

            // Generate Active Orders
            for ($i = 0; $i < $numActive; $i++) {
                $this->createRandomOrder($location, $locationTables, $products, $customers, false, now());
            }

            // Generate Completed Orders for today
            for ($i = 0; $i < $numCompleted; $i++) {
                $this->createRandomOrder($location, $locationTables, $products, $customers, true, now());
            }

            // Generate 2 years of Historical Orders
            for ($daysBack = 730; $daysBack > 0; $daysBack--) {
                $date = now()->subDays($daysBack);
                $isWeekend = in_array($date->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY]);
                $isRamadan = $this->isRamadan($date);

                // Sales volume for a standard Bangladeshi restaurant
                if ($isRamadan) {
                    $numOrdersToday = $isWeekend ? rand(15, 25) : rand(8, 15);
                } else {
                    $numOrdersToday = $isWeekend ? rand(20, 35) : rand(5, 12);
                }

                for ($i = 0; $i < $numOrdersToday; $i++) {
                    $orderTime = $this->getRandomOrderTime($date, $isRamadan);
                    $this->createRandomOrder($location, $locationTables, $products, $customers, true, $orderTime);
                }
            }
        }
    }

    private function isRamadan(Carbon $date): bool
    {
        // Approximations for past 2 years (2024 to 2026 range)
        $year = $date->year;
        if ($year == 2024 && $date->between(Carbon::create(2024, 3, 11), Carbon::create(2024, 4, 9))) return true;
        if ($year == 2025 && $date->between(Carbon::create(2025, 3, 1), Carbon::create(2025, 3, 30))) return true;
        if ($year == 2026 && $date->between(Carbon::create(2026, 2, 18), Carbon::create(2026, 3, 19))) return true;
        
        return false;
    }

    private function getRandomOrderTime(Carbon $date, bool $isRamadan): Carbon
    {
        $date = clone $date;
        if ($isRamadan) {
            // High at Iftar (17-19), Sahari (3-5), Dinner (20-23)
            $slots = [
                ['start' => 3, 'end' => 4, 'weight' => 15],
                ['start' => 17, 'end' => 19, 'weight' => 55],
                ['start' => 20, 'end' => 23, 'weight' => 30],
            ];
        } else {
            // High from 2 PM to 9 PM
            $slots = [
                ['start' => 12, 'end' => 13, 'weight' => 10], // Lunch
                ['start' => 14, 'end' => 21, 'weight' => 75], // High sales 2pm-9pm
                ['start' => 22, 'end' => 23, 'weight' => 15], // Late dinner
            ];
        }

        $rand = rand(1, 100);
        $cumulative = 0;
        $selectedSlot = $slots[0];
        
        foreach ($slots as $slot) {
            $cumulative += $slot['weight'];
            if ($rand <= $cumulative) {
                $selectedSlot = $slot;
                break;
            }
        }

        $hour = rand($selectedSlot['start'], $selectedSlot['end']);
        $minute = rand(0, 59);

        return $date->setTime($hour, $minute);
    }

    private function createRandomOrder($location, $locationTables, $products, $customers, $isCompleted, $date = null)
    {
        $date = $date ?? now();
        
        // Food type/Order type mapping for Bangladesh: heavily skewed towards delivery and dine_in
        $orderTypes = [
            'dine_in' => 40,
            'takeaway' => 20,
            'delivery' => 35,
            'catering' => 5
        ];
        $rand = rand(1, 100);
        $cumulative = 0;
        $type = 'dine_in';
        foreach ($orderTypes as $t => $weight) {
            $cumulative += $weight;
            if ($rand <= $cumulative) {
                $type = $t;
                break;
            }
        }
        
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

        $subtotal = 0;
        $orderItems = [];
        // BD Restaurants typically have 2-5 items per order
        $itemCount = rand(2, 5);
        for ($j = 0; $j < $itemCount; $j++) {
            $product = $products->random();
            $qty = rand(1, 3);
            $price = $product->sale_price ?? $product->price;
            $subtotal += $qty * $price;
            $orderItems[] = [
                'product_id' => $product->id,
                'quantity' => $qty,
                'price' => $price,
            ];
        }

        $tax = $subtotal * 0.1; // 10% VAT
        // Delivery charge in BD is usually 50-100 BDT
        $deliveryCharge = in_array($type, ['delivery', 'catering']) ? rand(50, 100) : 0;
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
            'delivery_time' => in_array($type, ['takeaway', 'delivery', 'catering']) ? (clone $date)->addMinutes(rand(30, 90)) : null,
            'delivery_address' => in_array($type, ['delivery', 'catering']) ? $customer->address ?? 'Dhaka, Bangladesh' : null,
            'latitude' => in_array($type, ['delivery', 'catering']) ? (23.8103 + (rand(-100, 100) / 10000)) : null,
            'longitude' => in_array($type, ['delivery', 'catering']) ? (90.4125 + (rand(-100, 100) / 10000)) : null,
        ]);
        
        $order->timestamps = false;
        $order->update([
            'created_at' => $date,
            'updated_at' => $date,
        ]);
        $order->timestamps = true;

        foreach ($orderItems as $item) {
            $order->items()->create($item);
        }

        if ($paymentStatus === 'paid') {
            $methods = ['cash', 'cash', 'mfs', 'mfs', 'card'];
            $order->payments()->create([
                // Mobile Financial Services (bkash, nagad) are very popular in BD
                'method' => $methods[array_rand($methods)],
                'amount' => $total,
                'status' => 'completed',
            ]);
        }
    }
}
