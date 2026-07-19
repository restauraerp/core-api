<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Location;
use App\Models\User;
use App\Models\InventoryItem;
use Carbon\Carbon;

class AccountingSeeder extends Seeder
{
    public function run(): void
    {
        $locations = Location::all();
        $admin = User::first();
        $items = InventoryItem::all();
        
        if ($locations->isEmpty() || $items->isEmpty() || !$admin) {
            $this->command->warn('Ensure Locations, Users, and InventoryItems are seeded before Accounting.');
            return;
        }

        // 1. Seed Suppliers
        $suppliersData = [
            [
                'name' => 'Bengal Fresh Produce',
                'contact_name' => 'Karim Rahman',
                'phone' => '01711223344',
                'email' => 'contact@bengalfresh.com',
                'address' => 'Kawran Bazar, Dhaka',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Italian Imports BD',
                'contact_name' => 'Luigi Rossi',
                'phone' => '01811556677',
                'email' => 'sales@italianimports.com.bd',
                'address' => 'Gulshan 2, Dhaka',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Prime Meats & Poultry',
                'contact_name' => 'Abul Hasan',
                'phone' => '01911998877',
                'email' => 'orders@primemeats.bd',
                'address' => 'Mirpur, Dhaka',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ];
        
        DB::table('suppliers')->insert($suppliersData);
        $supplierIds = DB::table('suppliers')->pluck('id')->toArray();
        
        $purchaseOrdersData = [];
        $purchaseItemsData = [];
        $ledgersData = [];
        $expensesData = [];
        
        $nextPurchaseOrderId = (DB::table('purchase_orders')->max('id') ?? 0) + 1;
        $nextExpenseId = (DB::table('expenses')->max('id') ?? 0) + 1;
        
        foreach ($locations as $location) {
            // Generate 2 years of Monthly Expenses (Rent, Salary, Utilities)
            for ($monthsBack = 24; $monthsBack >= 0; $monthsBack--) {
                $date = now()->subMonths($monthsBack)->startOfMonth()->addDays(rand(1, 5));
                $dateString = $date->toDateTimeString();
                
                // 1. Rent Expense
                $rentAmount = rand(150000, 250000);
                $expensesData[] = [
                    'id' => $nextExpenseId,
                    'location_id' => $location->id,
                    'category' => 'Rent',
                    'amount' => $rentAmount,
                    'logged_by' => $admin->id,
                    'receipt_url' => null,
                    'created_at' => $dateString,
                    'updated_at' => $dateString,
                ];
                
                $ledgersData[] = [
                    'location_id' => $location->id,
                    'transaction_type' => 'expense',
                    'amount' => -$rentAmount,
                    'reference_id' => $nextExpenseId,
                    'description' => 'Monthly Rent Expense',
                    'created_at' => $dateString,
                    'updated_at' => $dateString,
                ];
                $nextExpenseId++;
                
                // 2. Salary Expense
                $salaryAmount = rand(300000, 500000); // Consolidated salary for the branch
                $expensesData[] = [
                    'id' => $nextExpenseId,
                    'location_id' => $location->id,
                    'category' => 'Salary',
                    'amount' => $salaryAmount,
                    'logged_by' => $admin->id,
                    'receipt_url' => null,
                    'created_at' => $dateString,
                    'updated_at' => $dateString,
                ];
                
                $ledgersData[] = [
                    'location_id' => $location->id,
                    'transaction_type' => 'salary',
                    'amount' => -$salaryAmount,
                    'reference_id' => $nextExpenseId,
                    'description' => 'Employee Salaries',
                    'created_at' => $dateString,
                    'updated_at' => $dateString,
                ];
                $nextExpenseId++;
                
                // 3. Utilities / Marketing
                $utilityAmount = rand(50000, 100000);
                $expensesData[] = [
                    'id' => $nextExpenseId,
                    'location_id' => $location->id,
                    'category' => 'Utilities',
                    'amount' => $utilityAmount,
                    'logged_by' => $admin->id,
                    'receipt_url' => null,
                    'created_at' => $dateString,
                    'updated_at' => $dateString,
                ];
                
                $ledgersData[] = [
                    'location_id' => $location->id,
                    'transaction_type' => 'expense',
                    'amount' => -$utilityAmount,
                    'reference_id' => $nextExpenseId,
                    'description' => 'Utilities & Operational Expenses',
                    'created_at' => $dateString,
                    'updated_at' => $dateString,
                ];
                $nextExpenseId++;
                
                // 4. Generate 2-3 Purchase Orders per month
                $numPurchases = rand(2, 3);
                for ($p = 0; $p < $numPurchases; $p++) {
                    $purchaseDate = clone $date;
                    $purchaseDate->addDays(rand(1, 20));
                    $purchaseDateStr = $purchaseDate->toDateTimeString();
                    
                    $supplierId = $supplierIds[array_rand($supplierIds)];
                    
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
                            'purchase_order_id' => $nextPurchaseOrderId,
                            'inventory_item_id' => $item->id,
                            'quantity' => $qty,
                            'price' => $price,
                        ];
                    }
                    
                    $purchaseOrdersData[] = [
                        'id' => $nextPurchaseOrderId,
                        'supplier_id' => $supplierId,
                        'location_id' => $location->id,
                        'created_by' => $admin->id,
                        'total_amount' => $totalAmount,
                        'status' => 'received',
                        'created_at' => $purchaseDateStr,
                        'updated_at' => $purchaseDateStr,
                    ];
                    
                    $purchaseItemsData = array_merge($purchaseItemsData, $orderItems);
                    
                    // Accounting Ledger for Purchase
                    $ledgersData[] = [
                        'location_id' => $location->id,
                        'transaction_type' => 'purchase',
                        'amount' => -$totalAmount, // Outflow
                        'reference_id' => $nextPurchaseOrderId,
                        'description' => 'Inventory Purchase Order #' . $nextPurchaseOrderId,
                        'created_at' => $purchaseDateStr,
                        'updated_at' => $purchaseDateStr,
                    ];
                    
                    $nextPurchaseOrderId++;
                }
            }
        }
        
        // Insert data in chunks
        foreach (array_chunk($expensesData, 1000) as $chunk) {
            DB::table('expenses')->insert($chunk);
        }
        
        foreach (array_chunk($purchaseOrdersData, 1000) as $chunk) {
            DB::table('purchase_orders')->insert($chunk);
        }
        
        foreach (array_chunk($purchaseItemsData, 1000) as $chunk) {
            DB::table('purchase_items')->insert($chunk);
        }
        
        foreach (array_chunk($ledgersData, 1000) as $chunk) {
            DB::table('accounting_ledgers')->insert($chunk);
        }
        
        $this->command->info('✅ AccountingSeeder: Seeded Suppliers, Purchases, Expenses, and Ledgers.');
    }
}
