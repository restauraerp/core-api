<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Table;
use App\Models\User;
use App\Support\Orders\OrderFlow;
use Carbon\Carbon;
use Database\Seeders\Concerns\SeedsTenantData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class OrderSeeder extends Seeder
{
    use SeedsTenantData;

    private int $nextOrderId = 1;

    private int $nextExpenseId = 1;

    private int $nextIncomeId = 1;

    private int $nextPurchaseOrderId = 1;

    private int $nextConsumptionLogId = 1;

    private ?int $incomeHeaderId = null;

    private ?int $hallRentalHeaderId = null;

    private ?int $otherIncomeHeaderId = null;

    private ?int $rentHeaderId = null;

    private ?int $salaryHeaderId = null;

    private ?int $utilitiesHeaderId = null;

    private ?int $purchaseHeaderId = null;

    /**
     * How much of the purchase history is still on the shelf.
     *
     * Purchase orders are what put goods in a restaurant, so the demo's stock
     * levels are computed from them rather than invented separately. Summing
     * two years of deliveries would leave the kitchen holding tonnes of rice,
     * so everything older than this window counts as cooked and sold, and the
     * opening delivery below lands inside it.
     *
     * 60 days keeps the opening delivery (~59 days ago) well outside the
     * ranges a demo visitor naturally reaches first — this week, last week,
     * this month, last month, this quarter — so the profit report shows a
     * healthy margin rather than a one-off capital expenditure.
     */
    private const STOCK_WINDOW_DAYS = 60;

    public function run(): void
    {
        $products = Product::all();
        $customers = Customer::all();
        $tables = Table::all();
        $locations = Location::all();
        $admin = User::first();
        // Raw builder query - scope by hand so a tenant's purchase orders never
        // reference another restaurant's supplier.
        $suppliers = DB::table('suppliers')->where('tenant_id', $this->tenantId())->pluck('id')->toArray();
        $inventoryItems = InventoryItem::all();

        if ($products->isEmpty() || $customers->isEmpty() || $tables->isEmpty()) {
            $this->command->warn('Ensure Products, Customers, and Tables are seeded before Orders.');

            return;
        }

        $this->nextOrderId = $this->reserveIdBlock('orders');
        $this->nextExpenseId = $this->reserveIdBlock('expenses');
        $this->nextIncomeId = $this->reserveIdBlock('incomes');
        $this->nextPurchaseOrderId = $this->reserveIdBlock('purchase_orders');
        $this->nextConsumptionLogId = $this->reserveIdBlock('consumption_logs');

        // Load accounting header IDs so expenses and ledger entries can be
        // categorised. AccountingSeeder must have run first.
        $headersByName = DB::table('accounting_headers')
            ->where('tenant_id', $this->tenantId())
            ->pluck('id', 'name');

        $this->incomeHeaderId   = $headersByName['Food & Beverage Sales'] ?? null;
        $this->hallRentalHeaderId = $headersByName['Hall & Event Rental']  ?? null;
        $this->otherIncomeHeaderId = $headersByName['Other Income']        ?? null;
        $this->rentHeaderId     = $headersByName['Rent & Property']       ?? null;
        $this->salaryHeaderId   = $headersByName['Staff & Salaries']      ?? null;
        $this->utilitiesHeaderId = $headersByName['Utilities & Services'] ?? null;
        $this->purchaseHeaderId = $headersByName['Inventory Purchases']   ?? null;

        $ordersData = [];
        $orderItemsData = [];
        $paymentsData = [];
        $ledgersData = [];
        $expensesData = [];
        $incomesData = [];
        $purchaseOrdersData = [];
        $purchaseItemsData = [];
        $consumptionLogsData = [];
        $chunkSize = 1000;

        // Items that get written off periodically (waste, prep losses, etc.)
        $consumableItems = $inventoryItems->whereIn('title', [
            'Cooking Oil (Soyabean)', 'Fresh Tomato', 'Salt', 'Red Onion', 'Garlic',
            'Coriander Leaves', 'Fresh Basil', 'Milk', 'Butter', 'Lemon',
            'Green Chilli', 'Ginger', 'Coriander Powder', 'Black Pepper',
        ])->values();

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
                    $this->generateMonthlyIncome($date, $location, $admin, $incomesData, $ledgersData);
                }

                // 3. Generate Random Purchase Orders (approx 10% chance per day)
                if (rand(1, 100) <= 10) {
                    $this->generatePurchaseOrder($date, $location, $admin, $suppliers, $inventoryItems, $purchaseOrdersData, $purchaseItemsData, $ledgersData);
                }

                // 4. Weekly consumption log: every Monday, write off prep waste
                //    for a handful of perishable items.
                if ($date->dayOfWeek === Carbon::MONDAY && $consumableItems->isNotEmpty()) {
                    $this->generateWeeklyConsumptionLogs($date, $location, $admin, $consumableItems, $consumptionLogsData);
                }

                // 5. The delivery that stocks the kitchen for the week on show.
                //    Every item arrives on one order, so every stock level in
                //    the demo traces back to a purchase order a visitor can
                //    open and read.
                if ($daysBack === self::STOCK_WINDOW_DAYS - 1) {
                    $this->generateOpeningDelivery($date, $location, $admin, $suppliers, $inventoryItems, $purchaseOrdersData, $purchaseItemsData, $ledgersData);
                }

                // Flush chunks to maintain speed & chronological insert boundaries
                if (count($ordersData) >= $chunkSize || count($expensesData) >= $chunkSize || count($incomesData) >= $chunkSize || count($purchaseOrdersData) >= $chunkSize) {
                    $this->insertChunks($ordersData, $orderItemsData, $paymentsData, $ledgersData, $expensesData, $incomesData, $purchaseOrdersData, $purchaseItemsData, $consumptionLogsData);
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
        $this->insertChunks($ordersData, $orderItemsData, $paymentsData, $ledgersData, $expensesData, $incomesData, $purchaseOrdersData, $purchaseItemsData, $consumptionLogsData);

        $this->settleInventoryFromPurchases();
        $this->attachReceiptsToRecentOrders();

        $this->command->info('✅ OrderSeeder: Chronologically Seeded Orders, Expenses, Income, Purchases and Accounting Ledgers for 2 Years.');
    }

    private function insertChunks(&$ordersData, &$orderItemsData, &$paymentsData, &$ledgersData, &$expensesData, &$incomesData, &$purchaseOrdersData, &$purchaseItemsData, &$consumptionLogsData)
    {
        // Every insert below is a raw query-builder call, so BelongsToTenant
        // never sees these rows - stampTenant() supplies the tenant_id that the
        // creating hook would otherwise have added.
        if (count($ordersData) > 0) {
            DB::table('orders')->insert($this->stampTenant($ordersData));
        }
        foreach (array_chunk($orderItemsData, 2000) as $chunk) {
            DB::table('order_items')->insert($this->stampTenant($chunk));
        }
        foreach (array_chunk($paymentsData, 2000) as $chunk) {
            DB::table('payments')->insert($this->stampTenant($chunk));
        }

        if (count($expensesData) > 0) {
            DB::table('expenses')->insert($this->stampTenant($expensesData));
        }
        if (count($incomesData) > 0) {
            DB::table('incomes')->insert($this->stampTenant($incomesData));
        }
        if (count($purchaseOrdersData) > 0) {
            DB::table('purchase_orders')->insert($this->stampTenant($purchaseOrdersData));
        }
        foreach (array_chunk($purchaseItemsData, 2000) as $chunk) {
            DB::table('purchase_items')->insert($this->stampTenant($chunk));
        }

        foreach (array_chunk($ledgersData, 2000) as $chunk) {
            DB::table('accounting_ledgers')->insert($this->stampTenant($chunk));
        }

        if (count($consumptionLogsData) > 0) {
            foreach (array_chunk($consumptionLogsData, 2000) as $chunk) {
                DB::table('consumption_logs')->insert($this->stampTenant($chunk));
            }
        }

        $ordersData = [];
        $orderItemsData = [];
        $paymentsData = [];
        $ledgersData = [];
        $expensesData = [];
        $incomesData = [];
        $purchaseOrdersData = [];
        $purchaseItemsData = [];
        $consumptionLogsData = [];
    }

    /**
     * Read the table's AUTO_INCREMENT and bump it far ahead so concurrent
     * real-tenant inserts (the app is live during demo refresh) land above
     * the seeder's explicit-ID range instead of colliding with it.
     */
    private function reserveIdBlock(string $table, int $headroom = 200_000): int
    {
        $row = DB::selectOne(
            'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        $start = max((int) ($row->AUTO_INCREMENT ?? 1), (DB::table($table)->max('id') ?? 0) + 1);

        DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = " . ($start + $headroom));

        return $start;
    }

    /**
     * The money a restaurant takes that never passes through the till.
     *
     * Without this the demo's Income screen was an empty table and the profit
     * report's "Other Income" line was always zero, so neither showed what the
     * feature is for. Kept deliberately smaller than the food revenue it sits
     * beside - a demo where hall rental rivals the kitchen would misrepresent
     * the business, and the profit margin is the number visitors read first.
     *
     * Hall rental lands every month; the one-off receipts do not, so that the
     * list has gaps in it the way a real one does.
     */
    private function generateMonthlyIncome($date, $location, $admin, &$incomesData, &$ledgersData)
    {
        $dateString = (clone $date)->setTime(11, 0)->toDateTimeString();

        // Hall & event rental - the reliable monthly earner.
        $rentalAmount = rand(25000, 60000);
        $incomesData[] = [
            'id' => $this->nextIncomeId,
            'location_id' => $location->id,
            'category' => 'Hall Rental',
            'header_id' => $this->hallRentalHeaderId,
            'amount' => $rentalAmount,
            'logged_by' => $admin->id ?? 1,
            'receipt_url' => null,
            'created_at' => $dateString,
            'updated_at' => $dateString,
        ];
        $ledgersData[] = [
            'location_id' => $location->id,
            'transaction_type' => 'income',
            'amount' => $rentalAmount,
            'reference_id' => $this->nextIncomeId,
            'description' => 'Hall & Event Rental',
            'header_id' => $this->hallRentalHeaderId,
            'created_at' => $dateString,
            'updated_at' => $dateString,
        ];
        $this->nextIncomeId++;

        // Scrap sales, vendor rebates, recovered deposits - roughly every
        // other month rather than on a schedule.
        if (rand(1, 100) <= 55) {
            $otherAmount = rand(4000, 15000);
            $otherCategory = ['Scrap Sale', 'Vendor Commission', 'Equipment Sale', 'Recovered Deposit'][rand(0, 3)];

            $incomesData[] = [
                'id' => $this->nextIncomeId,
                'location_id' => $location->id,
                'category' => $otherCategory,
                'header_id' => $this->otherIncomeHeaderId,
                'amount' => $otherAmount,
                'logged_by' => $admin->id ?? 1,
                'receipt_url' => null,
                'created_at' => $dateString,
                'updated_at' => $dateString,
            ];
            $ledgersData[] = [
                'location_id' => $location->id,
                'transaction_type' => 'income',
                'amount' => $otherAmount,
                'reference_id' => $this->nextIncomeId,
                'description' => $otherCategory,
                'header_id' => $this->otherIncomeHeaderId,
                'created_at' => $dateString,
                'updated_at' => $dateString,
            ];
            $this->nextIncomeId++;
        }
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
            'header_id' => $this->rentHeaderId,
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
            'header_id' => $this->rentHeaderId,
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
            'header_id' => $this->salaryHeaderId,
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
            'header_id' => $this->salaryHeaderId,
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
            'header_id' => $this->utilitiesHeaderId,
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
            'header_id' => $this->utilitiesHeaderId,
            'created_at' => $dateString,
            'updated_at' => $dateString,
        ];
        $this->nextExpenseId++;
    }

    private function generatePurchaseOrder($date, $location, $admin, $suppliers, $items, &$purchaseOrdersData, &$purchaseItemsData, &$ledgersData)
    {
        if (empty($suppliers) || $items->isEmpty()) {
            return;
        }
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
                'created_at' => $dateString,
                'updated_at' => $dateString,
            ];
        }

        $purchaseOrdersData[] = [
            'id' => $this->nextPurchaseOrderId,
            'supplier_id' => $supplierId,
            'location_id' => $location->id,
            'created_by' => $admin->id ?? 1,
            'total_amount' => $totalAmount,
            'status' => 'received',
            // A received order's quantities are in inventory - settling the
            // levels below is what makes that true.
            'stock_applied' => true,
            // Null rather than omitted: a batch insert takes its column list
            // from the first row, so every row in the batch must carry the same
            // keys as the opening delivery below, which does set notes.
            'notes' => null,
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
            'description' => 'Inventory Purchase Order #'.$this->nextPurchaseOrderId,
            'header_id' => $this->purchaseHeaderId,
            'created_at' => $dateString,
            'updated_at' => $dateString,
        ];
        $this->nextPurchaseOrderId++;
    }

    /**
     * One delivery per outlet covering every inventory item, dated at the start
     * of the stock window.
     *
     * The random deliveries above only touch a handful of items each, so
     * without this most of the store cupboard would read zero. Quantities are
     * pitched against each item's minimum level, and a few land below it - a
     * demo where nothing is ever running low does not show the low-stock
     * warnings off.
     */
    private function generateOpeningDelivery($date, $location, $admin, $suppliers, $items, &$purchaseOrdersData, &$purchaseItemsData, &$ledgersData)
    {
        if (empty($suppliers) || $items->isEmpty()) {
            return;
        }

        $dateString = (clone $date)->setTime(8, 30)->toDateTimeString();
        $supplierId = $suppliers[array_rand($suppliers)];
        $totalAmount = 0;

        foreach ($items as $item) {
            $minimum = (float) ($item->min_stock_level ?: 10);
            // Roughly one in six items arrives short of its minimum.
            $factor = rand(1, 6) === 1 ? rand(30, 90) / 100 : rand(120, 320) / 100;
            $qty = max(1, round($minimum * $factor, 2));
            $price = $item->cost_per_unit ?? rand(50, 500);
            $totalAmount += $qty * $price;

            $purchaseItemsData[] = [
                'purchase_order_id' => $this->nextPurchaseOrderId,
                'inventory_item_id' => $item->id,
                'quantity' => $qty,
                'price' => $price,
                'created_at' => $dateString,
                'updated_at' => $dateString,
            ];
        }

        $purchaseOrdersData[] = [
            'id' => $this->nextPurchaseOrderId,
            'supplier_id' => $supplierId,
            'location_id' => $location->id,
            'created_by' => $admin->id ?? 1,
            'total_amount' => $totalAmount,
            'status' => 'received',
            'stock_applied' => true,
            'notes' => 'Weekly restock - full store cupboard.',
            'created_at' => $dateString,
            'updated_at' => $dateString,
        ];

        $ledgersData[] = [
            'location_id' => $location->id,
            'transaction_type' => 'purchase',
            'amount' => -$totalAmount,
            'reference_id' => $this->nextPurchaseOrderId,
            'description' => 'Inventory Purchase Order #'.$this->nextPurchaseOrderId,
            'header_id' => $this->purchaseHeaderId,
            'created_at' => $dateString,
            'updated_at' => $dateString,
        ];

        $this->nextPurchaseOrderId++;
    }

    /**
     * Weekly prep-waste write-offs: picks a handful of perishable items and
     * logs a realistic consumption quantity for each outlet.
     */
    private function generateWeeklyConsumptionLogs($date, $location, $admin, $consumableItems, &$consumptionLogsData): void
    {
        if ($consumableItems->isEmpty()) {
            return;
        }

        $dateStr = $date->toDateString();
        $reasons = ['Prep waste', 'Spillage', 'Quality trim', 'Kitchen use', 'Daily prep'];
        $count = rand(3, 6);

        $picked = $consumableItems->shuffle()->take($count);

        foreach ($picked as $item) {
            // Quantity scaled to the item's min_stock_level so it looks plausible
            $base = (float) ($item->min_stock_level ?: 5);
            $qty = round($base * (rand(5, 20) / 100), 3); // 5–20% of min stock level

            $consumptionLogsData[] = [
                'id' => $this->nextConsumptionLogId++,
                'inventory_item_id' => $item->id,
                'location_id' => $location->id,
                'quantity' => $qty,
                'reason' => $reasons[array_rand($reasons)],
                'consumed_at' => $dateStr,
                'logged_by' => $admin->id ?? 1,
                'created_at' => $date->toDateTimeString(),
                'updated_at' => $date->toDateTimeString(),
            ];
        }
    }

    /**
     * Set every stock level from the deliveries that are still inside the
     * window, so the inventory screens and the purchase orders tell the same
     * story.
     */
    private function settleInventoryFromPurchases(): void
    {
        $tenantId = $this->tenantId();
        $since = now()->subDays(self::STOCK_WINDOW_DAYS)->startOfDay();

        $delivered = DB::table('purchase_items as pi')
            ->join('purchase_orders as po', 'po.id', '=', 'pi.purchase_order_id')
            ->where('po.tenant_id', $tenantId)
            ->where('po.status', 'received')
            ->where('po.created_at', '>=', $since)
            ->groupBy('po.location_id', 'pi.inventory_item_id')
            ->selectRaw('po.location_id, pi.inventory_item_id, SUM(pi.quantity) as quantity')
            ->get();

        foreach ($delivered as $row) {
            DB::table('inventory_item_location')
                ->where('tenant_id', $tenantId)
                ->where('location_id', $row->location_id)
                ->where('inventory_item_id', $row->inventory_item_id)
                ->update(['quantity' => $row->quantity, 'updated_at' => now()]);
        }

        // The headline figure is the sum of the outlets, never a number of its
        // own - the same rule the API applies on every delivery.
        DB::update(
            'UPDATE inventory_items i SET i.current_stock = (
                SELECT COALESCE(SUM(l.quantity), 0) FROM inventory_item_location l
                WHERE l.inventory_item_id = i.id AND l.tenant_id = ?
            ) WHERE i.tenant_id = ?',
            [$tenantId, $tenantId]
        );
    }

    /**
     * Photograph-of-the-paperwork demo: the newest delivery at each outlet
     * carries scanned receipts, so the receipts gallery is not empty.
     */
    private function attachReceiptsToRecentOrders(): void
    {
        $source = database_path('seeders/images/receipts');
        $destination = storage_path('app/public/receipts');

        if (! File::exists($source)) {
            return;
        }

        if (! File::exists($destination)) {
            File::makeDirectory($destination, 0755, true);
        }

        File::copyDirectory($source, $destination);

        $receipts = collect(File::files($source))
            ->map(fn ($file) => 'receipts/'.$file->getFilename())
            ->values();

        if ($receipts->isEmpty()) {
            return;
        }

        // The opening delivery: the one order per outlet that carries every
        // item, and the one a visitor is most likely to open.
        $orders = PurchaseOrder::where('notes', 'Weekly restock - full store cupboard.')->get();

        foreach ($orders as $order) {
            foreach ($receipts as $url) {
                $order->receipts()->create(['url' => $url, 'type' => 'receipt']);
            }
        }
    }

    private function isRamadan(Carbon $date): bool
    {
        // Approximations for past 2 years (2024 to 2026 range)
        $year = $date->year;
        if ($year == 2024 && $date->between(Carbon::create(2024, 3, 11), Carbon::create(2024, 4, 9))) {
            return true;
        }
        if ($year == 2025 && $date->between(Carbon::create(2025, 3, 1), Carbon::create(2025, 3, 30))) {
            return true;
        }
        if ($year == 2026 && $date->between(Carbon::create(2026, 2, 18), Carbon::create(2026, 3, 19))) {
            return true;
        }

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
            'catering' => 5,
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
                $status = OrderFlow::SERVED;
            } elseif ($type === 'takeaway') {
                $status = OrderFlow::PACKED;
            } else {
                $status = OrderFlow::DELIVERED;
            }
        } else {
            // The stages an unfinished order can be sitting in, per type - the
            // same runs OrderFlow enforces. `pending` is not among them here:
            // an order only waits when it is scheduled for later, which is
            // decided below from its delivery time.
            if ($type === 'dine_in') {
                $validStatuses = [OrderFlow::COOKING, OrderFlow::READY_TO_SERVE, OrderFlow::SERVED];
            } elseif ($type === 'takeaway') {
                $validStatuses = [OrderFlow::COOKING, OrderFlow::READY_TO_SERVE, OrderFlow::PACKED];
            } else {
                $validStatuses = [OrderFlow::COOKING, OrderFlow::READY_TO_SERVE, OrderFlow::PACKED, OrderFlow::PICKED_UP, OrderFlow::DELIVERED];
            }
            $status = $validStatuses[array_rand($validStatuses)];
        }

        $customer = $customers->random();
        $subtotal = 0;
        $orderId = $this->nextOrderId++;
        $itemCount = rand(2, 5);
        $needsCooking = false;

        for ($j = 0; $j < $itemCount; $j++) {
            $product = $products->random();
            $qty = rand(1, 3);
            $price = $product->sale_price ?? $product->price;
            $subtotal += $qty * $price;
            $needsCooking = $needsCooking || (bool) $product->needs_cooking;

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

        // The same two rules the API applies when a real order is placed: an
        // order not due yet waits, and one with nothing to prepare never
        // reaches the kitchen.
        if (! $isCompleted) {
            if ($deliveryTime !== null && Carbon::parse($deliveryTime)->isFuture()) {
                $status = OrderFlow::PENDING;
            } elseif (! $needsCooking && $status === OrderFlow::COOKING) {
                $status = OrderFlow::READY_TO_SERVE;
            }
        }

        $ordersData[] = [
            'id' => $orderId,
            'location_id' => $location->id,
            'user_id' => 1,
            'customer_id' => $customer->id,
            'table_id' => ($type === 'dine_in' && $locationTables->isNotEmpty()) ? $locationTables->random()->id : null,
            'order_type' => $type,
            'status' => $status,
            'needs_cooking' => $needsCooking,
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
                'description' => 'Sale from Order #'.$orderId,
                'header_id' => $this->incomeHeaderId,
                'created_at' => $dateString,
                'updated_at' => $dateString,
            ];
        }
    }
}
