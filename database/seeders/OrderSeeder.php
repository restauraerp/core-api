<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Table;
use App\Models\Location;
use App\Models\User;
use App\Models\InventoryItem;
use Carbon\Carbon;

class OrderSeeder extends Seeder
{
    private int $nextOrderId = 1;
    private int $nextExpenseId = 1;
    private int $nextPurchaseOrderId = 1;

    public function run(): void
    {
        $products = Product::all();
        $customers = Customer::all();
        $tables = Table::all();
        $locations = Location::all();
        $admin = User::first();
        $suppliers = DB::table('suppliers')->pluck('id')->toArray();
        $inventoryItems = InventoryItem::all();

        if ($products->isEmpty() || $customers->isEmpty() || $tables->isEmpty()) {
            $this->command->warn('Ensure Products, Customers, and Tables are seeded before Orders.');
            return;
        }

        $this->nextOrderId = (DB::table('orders')->max('id') ?? 0) + 1;
        $this->nextExpenseId = (DB::table('expenses')->max('id') ?? 0) + 1;
        $this->nextPurchaseOrderId = (DB::table('purchase_orders')->max('id') ?? 0) + 1;
        
        $ordersData = [];
        $orderItemsData = [];
        $paymentsData = [];
        $ledgersData = [];
        $expensesData = [];
        $purchaseOrdersData = [];
        $purchaseItemsData = [];
        $chunkSize = 1000;

        // Loop chronologically from 730 days ago to today
        // Putting the days loop OUTSIDE the locations loop ensures perfectly ordered insertions across all locations
        for ($daysBack = 730; $daysBack >= 0; $daysBack--) {
            $date = now()->subDays($daysBack);
            $isWeekend = in_array($date->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY]);
            $isRamadan = $this->isRamadan($date);

            foreach ($locations as $location) {
                $locationTables = $tables->where('location_id', $location->id);
                
                // 1. Generate Orders
                if ($isRamadan) {
                    $numOrdersToday = $isWeekend ? rand(15, 25) : rand(8, 15);
                } else {
                    $numOrdersToday = $isWeekend ? rand(20, 35) : rand(5, 12);
                }

                for ($i = 0; $i < $numOrdersToday; $i++) {
                    $orderTime = $this->getRandomOrderTime($date, $isRamadan);
                    $this->generateOrderData($ordersData, $orderItemsData, $paymentsData, $ledgersData, $location, $locationTables, $products, $customers, true, $orderTime);
                }
                
                // 2. Generate Monthly Expenses on the 1st of the month
                if ($date->day === 1) {
                    $this->generateMonthlyExpenses($date, $location, $admin, $expensesData, $ledgersData);
                }
                
                // 3. Generate Random Purchase Orders (approx 10% chance per day)
                if (rand(1, 100) <= 10) {
                    $this->generatePurchaseOrder($date, $location, $admin, $suppliers, $inventoryItems, $purchaseOrdersData, $purchaseItemsData, $ledgersData);
                }

                // Flush chunks to maintain speed & chronological insert boundaries
                if (count($ordersData) >= $chunkSize || count($expensesData) >= $chunkSize || count($purchaseOrdersData) >= $chunkSize) {
                    $this->insertChunks($ordersData, $orderItemsData, $paymentsData, $ledgersData, $expensesData, $purchaseOrdersData, $purchaseItemsData);
                }
            }
        }
        
        // Generate a few active (uncompleted) orders for today
        foreach ($locations as $location) {
            $locationTables = $tables->where('location_id', $location->id);
            $numActive = rand(5, 10);
            for ($i = 0; $i < $numActive; $i++) {
                $this->generateOrderData($ordersData, $orderItemsData, $paymentsData, $ledgersData, $location, $locationTables, $products, $customers, false, now());
            }
        }

        // Insert remaining
        $this->insertChunks($ordersData, $orderItemsData, $paymentsData, $ledgersData, $expensesData, $purchaseOrdersData, $purchaseItemsData);
        
        $this->command->info('✅ OrderSeeder: Chronologically Seeded Orders, Expenses, Purchases and Accounting Ledgers for 2 Years.');
    }

    private function insertChunks(&$ordersData, &$orderItemsData, &$paymentsData, &$ledgersData, &$expensesData, &$purchaseOrdersData, &$purchaseItemsData)
    {
        if (count($ordersData) > 0) DB::table('orders')->insert($ordersData);
        foreach (array_chunk($orderItemsData, 2000) as $chunk) {
            DB::table('order_items')->insert($chunk);
        }
        foreach (array_chunk($paymentsData, 2000) as $chunk) {
            DB::table('payments')->insert($chunk);
        }

        if (count($expensesData) > 0) DB::table('expenses')->insert($expensesData);
        if (count($purchaseOrdersData) > 0) DB::table('purchase_orders')->insert($purchaseOrdersData);
        foreach (array_chunk($purchaseItemsData, 2000) as $chunk) {
            DB::table('purchase_items')->insert($chunk);
        }

        foreach (array_chunk($ledgersData, 2000) as $chunk) {
            DB::table('accounting_ledgers')->insert($chunk);
        }
        
        $ordersData = [];
        $orderItemsData = [];
        $paymentsData = [];
        $ledgersData = [];
        $expensesData = [];
        $purchaseOrdersData = [];
        $purchaseItemsData = [];
    }

    private function generateMonthlyExpenses($date, $location, $admin, &$expensesData, &$ledgersData)
    {
        $dateString = (clone $date)->setTime(9, 0)->toDateTimeString();

        // Rent Expense
        $rentAmount = rand(150000, 250000);
        $expensesData[] = [
            'id' => $this->nextExpenseId,
            'location_id' => $location->id,
            'category' => 'Rent',
            'amount' => $rentAmount,
            'logged_by' => $admin->id ?? 1,
            'receipt_url' => null,
            'created_at' => $dateString,
            'updated_at' => $dateString,
        ];
        $ledgersData[] = [
            'location_id' => $location->id,
            'transaction_type' => 'expense',
            'amount' => -$rentAmount,
            'reference_id' => $this->nextExpenseId,
            'description' => 'Monthly Rent Expense',
            'created_at' => $dateString,
            'updated_at' => $dateString,
        ];
        $this->nextExpenseId++;

        // Salary Expense
        $salaryAmount = rand(300000, 500000);
        $expensesData[] = [
            'id' => $this->nextExpenseId,
            'location_id' => $location->id,
            'category' => 'Salary',
            'amount' => $salaryAmount,
            'logged_by' => $admin->id ?? 1,
            'receipt_url' => null,
            'created_at' => $dateString,
            'updated_at' => $dateString,
        ];
        $ledgersData[] = [
            'location_id' => $location->id,
            'transaction_type' => 'salary',
            'amount' => -$salaryAmount,
            'reference_id' => $this->nextExpenseId,
            'description' => 'Employee Salaries',
            'created_at' => $dateString,
            'updated_at' => $dateString,
        ];
        $this->nextExpenseId++;

        // Utilities
        $utilityAmount = rand(50000, 100000);
        $expensesData[] = [
            'id' => $this->nextExpenseId,
            'location_id' => $location->id,
            'category' => 'Utilities',
            'amount' => $utilityAmount,
            'logged_by' => $admin->id ?? 1,
            'receipt_url' => null,
            'created_at' => $dateString,
            'updated_at' => $dateString,
        ];
        $ledgersData[] = [
            'location_id' => $location->id,
            'transaction_type' => 'expense',
            'amount' => -$utilityAmount,
            'reference_id' => $this->nextExpenseId,
            'description' => 'Utilities & Operational Expenses',
            'created_at' => $dateString,
            'updated_at' => $dateString,
        ];
        $this->nextExpenseId++;
    }

    private function generatePurchaseOrder($date, $location, $admin, $suppliers, $items, &$purchaseOrdersData, &$purchaseItemsData, &$ledgersData)
    {
        if (empty($suppliers) || $items->isEmpty()) return;
        $dateString = (clone $date)->setTime(rand(10, 16), rand(0, 59))->toDateTimeString();

        $supplierId = $suppliers[array_rand($suppliers)];
        $totalAmount = 0;
        $numItems = rand(3, 8);
        $orderItems = [];

        for ($i = 0; $i < $numItems; $i++) {
            $item = $items->random();
            $qty = rand(10, 50);
            $price = $item->cost_per_unit ?? rand(50, 500);
            $subtotal = $qty * $price;
            $totalAmount += $subtotal;
            
            $orderItems[] = [
                'purchase_order_id' => $this->nextPurchaseOrderId,
                'inventory_item_id' => $item->id,
                'quantity' => $qty,
                'price' => $price,
            ];
        }

        $purchaseOrdersData[] = [
            'id' => $this->nextPurchaseOrderId,
            'supplier_id' => $supplierId,
            'location_id' => $location->id,
            'created_by' => $admin->id ?? 1,
            'total_amount' => $totalAmount,
            'status' => 'received',
            'created_at' => $dateString,
            'updated_at' => $dateString,
        ];
        
        foreach ($orderItems as $oi) {
            $purchaseItemsData[] = $oi;
        }

        $ledgersData[] = [
            'location_id' => $location->id,
            'transaction_type' => 'purchase',
            'amount' => -$totalAmount,
            'reference_id' => $this->nextPurchaseOrderId,
            'description' => 'Inventory Purchase Order #' . $this->nextPurchaseOrderId,
            'created_at' => $dateString,
            'updated_at' => $dateString,
        ];
        $this->nextPurchaseOrderId++;
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

    private function generateOrderData(&$ordersData, &$orderItemsData, &$paymentsData, &$ledgersData, $location, $locationTables, $products, $customers, $isCompleted, $date = null)
    {
        $date = $date ? clone $date : now();
        $dateString = $date->toDateTimeString();
        
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
        $orderId = $this->nextOrderId++;
        $itemCount = rand(2, 5);
        
        for ($j = 0; $j < $itemCount; $j++) {
            $product = $products->random();
            $qty = rand(1, 3);
            $price = $product->sale_price ?? $product->price;
            $subtotal += $qty * $price;
            
            $orderItemsData[] = [
                'order_id' => $orderId,
                'product_id' => $product->id,
                'quantity' => $qty,
                'price' => $price,
            ];
        }

        $tax = $subtotal * 0.1; // 10% VAT
        $deliveryCharge = in_array($type, ['delivery', 'catering']) ? rand(50, 100) : 0;
        $total = $subtotal + $tax + $deliveryCharge;
        $deliveryTime = in_array($type, ['takeaway', 'delivery', 'catering']) ? (clone $date)->addMinutes(rand(30, 90))->toDateTimeString() : null;

        $ordersData[] = [
            'id' => $orderId,
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
            'delivery_time' => $deliveryTime,
            'delivery_address' => in_array($type, ['delivery', 'catering']) ? $customer->address ?? 'Dhaka, Bangladesh' : null,
            'latitude' => in_array($type, ['delivery', 'catering']) ? (23.8103 + (rand(-100, 100) / 10000)) : null,
            'longitude' => in_array($type, ['delivery', 'catering']) ? (90.4125 + (rand(-100, 100) / 10000)) : null,
            'created_at' => $dateString,
            'updated_at' => $dateString,
        ];
        
        if ($paymentStatus === 'paid') {
            $methods = ['cash', 'cash', 'mfs', 'mfs', 'card'];
            $paymentsData[] = [
                'order_id' => $orderId,
                'method' => $methods[array_rand($methods)],
                'amount' => $total,
                'status' => 'completed',
                'created_at' => $dateString,
                'updated_at' => $dateString,
            ];
            
            $ledgersData[] = [
                'location_id' => $location->id,
                'transaction_type' => 'sale',
                'amount' => $total,
                'reference_id' => $orderId,
                'description' => 'Sale from Order #' . $orderId,
                'created_at' => $dateString,
                'updated_at' => $dateString,
            ];
        }
    }
}
