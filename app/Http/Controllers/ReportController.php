<?php

namespace App\Http\Controllers;

use App\Models\ConsumptionLog;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated reporting endpoints.
 *
 * These deliberately aggregate in SQL rather than returning order rows. The
 * previous approach (GET /orders?nopaginate=1 + reduce in the browser) hydrated
 * every matching order with its items, products, product images, payments,
 * customer and table, which exhausted PHP's memory limit for any range wider
 * than about a month.
 *
 * Timezone: orders.created_at is stored in the app timezone (Asia/Dhaka), so
 * MySQL's DATE()/HOUR() operate on local wall-clock time and the buckets are
 * already correct for the restaurant's day. No conversion is applied here, and
 * none should be applied on the client either.
 *
 * Window convention: `from` is inclusive, `to` is exclusive. That avoids the
 * "23:59:59" boundary which silently drops orders in the final second.
 */
class ReportController extends Controller
{
    /**
     * Orders in these states never count as sales. Compared case-insensitively
     * by MySQL's default collation, so 'Cancelled' and 'cancelled' both match.
     */
    private const EXCLUDED_STATUSES = ['cancelled', 'canceled', 'failed', 'refunded', 'void', 'rejected'];

    private const BUCKETS = ['hour', 'day', 'month'];

    /**
     * Queries built with the query builder (DB::table) never pass through
     * Eloquent, so the BelongsToTenant global scope does not apply to them and
     * they must be filtered by hand.
     */
    private function tenantId(): ?int
    {
        return app(TenantContext::class)->id();
    }

    /**
     * Base order query with the window, branch and status rules applied.
     */
    private function scope(Request $request)
    {
        $query = Order::query()->whereNotIn('status', self::EXCLUDED_STATUSES);

        [$from, $to] = $this->window($request);

        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<', $to);
        }

        $locationId = $request->input('location_id');
        if ($locationId !== null && $locationId !== '' && $locationId !== 'all') {
            $query->where('location_id', $locationId);
        }

        return $query;
    }

    /**
     * Resolve the reporting window. Bounds are honoured exactly as given - the
     * caller owns the definition of each named range (see the frontend's
     * lib/reportRange.ts), so a report never shifts underneath the user
     * between two requests a minute apart.
     *
     * Callers are expected to pass `to` as the exclusive end of the selected
     * period (e.g. tomorrow 00:00 for "today"), which keeps a scheduled order
     * dated next week out of this week's figures while still counting an order
     * placed for later today - matching what the dashboard reports.
     */
    private function window(Request $request): array
    {
        return [
            $request->filled('from') ? $request->input('from') : null,
            $request->filled('to') ? $request->input('to') : null,
        ];
    }

    private function bucket(Request $request): string
    {
        $bucket = $request->input('bucket', 'day');

        return in_array($bucket, self::BUCKETS, true) ? $bucket : 'day';
    }

    /**
     * Headline totals plus a time series, in a single response so the two can
     * never disagree with each other.
     *
     * GET /reports/sales?from=&to=&location_id=&bucket=hour|day|month
     */
    public function sales(Request $request)
    {
        $summary = (clone $this->scope($request))
            ->selectRaw('
                COUNT(*)                                                        as orders_count,
                COALESCE(SUM(total), 0)                                         as gross_revenue,
                COALESCE(SUM(subtotal), 0)                                      as item_revenue,
                COALESCE(SUM(tax_amount), 0)                                    as tax_total,
                COALESCE(SUM(discount_amount), 0)                               as discount_total,
                COALESCE(SUM(COALESCE(delivery_charge, 0)), 0)                  as delivery_total,
                COALESCE(SUM(CASE WHEN payment_status = "paid" THEN total ELSE 0 END), 0)     as collected_revenue,
                COALESCE(SUM(CASE WHEN payment_status <> "paid" OR payment_status IS NULL THEN total ELSE 0 END), 0) as outstanding_revenue,
                SUM(CASE WHEN payment_status <> "paid" OR payment_status IS NULL THEN 1 ELSE 0 END) as unpaid_orders
            ')
            ->first();

        $bucket = $this->bucket($request);
        $expression = match ($bucket) {
            'hour' => "DATE_FORMAT(created_at, '%Y-%m-%d %H:00')",
            'month' => "DATE_FORMAT(created_at, '%Y-%m')",
            default => "DATE(created_at)",
        };

        $series = (clone $this->scope($request))
            ->selectRaw("
                $expression                                     as bucket,
                COUNT(*)                                        as orders,
                COALESCE(SUM(total), 0)                         as revenue,
                COALESCE(SUM(subtotal), 0)                      as item_revenue,
                COALESCE(SUM(CASE WHEN payment_status = \"paid\" THEN total ELSE 0 END), 0) as collected
            ")
            ->groupBy(DB::raw($expression))
            ->orderBy(DB::raw('bucket'))
            ->get();

        $ordersCount = (int) $summary->orders_count;

        return response()->json([
            'bucket' => $bucket,
            'summary' => [
                'orders_count' => $ordersCount,
                'gross_revenue' => (float) $summary->gross_revenue,
                'item_revenue' => (float) $summary->item_revenue,
                'tax_total' => (float) $summary->tax_total,
                'discount_total' => (float) $summary->discount_total,
                'delivery_total' => (float) $summary->delivery_total,
                'collected_revenue' => (float) $summary->collected_revenue,
                'outstanding_revenue' => (float) $summary->outstanding_revenue,
                'unpaid_orders' => (int) $summary->unpaid_orders,
                'avg_order_value' => $ordersCount > 0 ? (float) $summary->gross_revenue / $ordersCount : 0.0,
            ],
            'series' => $series->map(fn ($row) => [
                'bucket' => $row->bucket,
                'orders' => (int) $row->orders,
                'revenue' => (float) $row->revenue,
                'item_revenue' => (float) $row->item_revenue,
                'collected' => (float) $row->collected,
            ]),
        ]);
    }

    /**
     * What each employee sold in the window.
     *
     * Grouped by `served_by_user_id`, never by `user_id`. The latter records
     * whichever account created the order, and a restaurant running one shared
     * till login would see a single "POS" employee credited with every sale in
     * the building - a report that looks fine and means nothing.
     *
     * Orders nobody was credited on are counted and returned as their own row
     * rather than dropped. Hiding them would make the report's total quietly
     * disagree with the sales report over the same window, and the size of that
     * unattributed row is itself worth seeing: it is how a manager knows
     * whether the staff are actually tagging their sales.
     *
     * GET /reports/staff?from=&to=&location_id=
     */
    public function staff(Request $request)
    {
        // Grouped without joining `users`, and the names resolved afterwards.
        // scope() writes its window and status filters unqualified - it is
        // shared with every other report - so joining a table that also has
        // `created_at` and `status` makes those clauses ambiguous and the query
        // fails outright. One extra indexed lookup is cheaper than qualifying
        // every column in a helper eight other reports depend on.
        $rows = (clone $this->scope($request))
            ->selectRaw('
                served_by_user_id                               as user_id,
                COUNT(*)                                        as orders_count,
                COALESCE(SUM(total), 0)                         as revenue,
                COALESCE(SUM(subtotal), 0)                      as item_revenue,
                COALESCE(SUM(discount_amount), 0)               as discount_total,
                COALESCE(SUM(CASE WHEN payment_status = "paid" THEN total ELSE 0 END), 0) as collected
            ')
            ->groupBy('served_by_user_id')
            ->orderByDesc('revenue')
            ->get();

        $names = User::query()
            ->whereIn('id', $rows->pluck('user_id')->filter()->all())
            ->pluck('name', 'id');

        $totalRevenue = (float) $rows->sum(fn ($row) => (float) $row->revenue);

        return response()->json([
            'summary' => [
                'employees' => $rows->whereNotNull('user_id')->count(),
                'total_revenue' => $totalRevenue,
                'attributed_revenue' => (float) $rows->whereNotNull('user_id')->sum(fn ($row) => (float) $row->revenue),
                'unattributed_orders' => (int) ($rows->firstWhere('user_id', null)->orders_count ?? 0),
            ],
            'employees' => $rows->map(function ($row) use ($totalRevenue, $names) {
                $revenue = (float) $row->revenue;
                $orders = (int) $row->orders_count;

                return [
                    'user_id' => $row->user_id === null ? null : (int) $row->user_id,
                    // Named here rather than left to the client, so a deleted
                    // employee's sales still read as something.
                    'name' => $names[$row->user_id] ?? 'Not attributed',
                    'orders_count' => $orders,
                    'revenue' => $revenue,
                    'item_revenue' => (float) $row->item_revenue,
                    'discount_total' => (float) $row->discount_total,
                    'collected' => (float) $row->collected,
                    'avg_order_value' => $orders > 0 ? $revenue / $orders : 0.0,
                    'share' => $totalRevenue > 0 ? $revenue / $totalRevenue : 0.0,
                ];
            })->values(),
        ]);
    }

    /**
     * Per-product totals for the window.
     *
     * Revenue here is the sum of line items (quantity x price), which is
     * pre-tax, pre-delivery and before order-level discounts - it reconciles
     * with `item_revenue` from sales(), not with `gross_revenue`.
     *
     * GET /reports/products?from=&to=&location_id=&limit=
     */
    public function products(Request $request)
    {
        $limit = min(max((int) $request->input('limit', 500), 1), 2000);

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            // The subquery is already tenant-scoped (it is an Eloquent Order
            // query), but stating it on order_items too lets MySQL use the
            // tenant-leading index instead of filtering after the join.
            ->where('order_items.tenant_id', $this->tenantId())
            ->whereIn('orders.id', (clone $this->scope($request))->select('id'))
            ->groupBy('order_items.product_id', 'products.name')
            ->selectRaw('
                order_items.product_id                                       as product_id,
                COALESCE(products.name, "Unknown Product")                   as name,
                COALESCE(SUM(order_items.quantity), 0)                       as quantity,
                COALESCE(SUM(order_items.quantity * order_items.price), 0)   as revenue,
                COUNT(DISTINCT order_items.order_id)                         as orders
            ')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'product_id' => $row->product_id,
                'name' => $row->name,
                'quantity' => (float) $row->quantity,
                'revenue' => (float) $row->revenue,
                'orders' => (int) $row->orders,
            ]),
        ]);
    }

    /**
     * Order/revenue distribution across the 24 hours of the day, summed over
     * every day in the window. Hours with no trade are returned as zeroes so
     * the chart has a continuous x-axis.
     *
     * GET /reports/hourly?from=&to=&location_id=
     */
    public function hourly(Request $request)
    {
        $rows = (clone $this->scope($request))
            ->selectRaw('
                HOUR(created_at)                as hour,
                COUNT(*)                        as orders,
                COALESCE(SUM(total), 0)         as revenue
            ')
            ->groupBy(DB::raw('HOUR(created_at)'))
            ->get()
            ->keyBy('hour');

        $data = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $row = $rows->get($hour);
            $data[] = [
                'hour' => $hour,
                'label' => sprintf('%02d:00', $hour),
                'orders' => $row ? (int) $row->orders : 0,
                'revenue' => $row ? (float) $row->revenue : 0.0,
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Stock on hand, lowest first.
     *
     * Stock lives on the inventory_item_location pivot; inventory_items.current_stock
     * mirrors the sum of it (maintained by PurchaseOrderStock) and is not read
     * here, so a branch filter and the all-branches total come from the same
     * place. When a branch is selected we report that branch's quantity;
     * otherwise the total across branches.
     *
     * GET /reports/inventory?location_id=
     */
    public function inventory(Request $request)
    {
        $locationId = $request->input('location_id');
        $scoped = $locationId !== null && $locationId !== '' && $locationId !== 'all';

        $stock = DB::table('inventory_item_location')
            // Without this the aggregate sums every tenant's stock rows before
            // the per-item lookup below discards the ones that do not match -
            // a full-table scan that grows with the whole platform.
            ->where('tenant_id', $this->tenantId())
            ->when($scoped, fn ($q) => $q->where('location_id', $locationId))
            ->groupBy('inventory_item_id')
            ->selectRaw('inventory_item_id, COALESCE(SUM(quantity), 0) as quantity')
            ->pluck('quantity', 'inventory_item_id');

        $items = InventoryItem::query()->orderBy('title')->get();

        $data = $items->map(function (InventoryItem $item) use ($stock) {
            $quantity = (float) ($stock[$item->id] ?? 0);
            $costPerUnit = (float) $item->cost_per_unit;
            $minLevel = (float) $item->min_stock_level;

            return [
                'id' => $item->id,
                'name' => $item->title ?: $item->description,
                'sku' => $item->sku,
                'unit' => $item->unit,
                'cost_per_unit' => $costPerUnit,
                'min_stock_level' => $minLevel,
                'quantity' => $quantity,
                'total_value' => $quantity * $costPerUnit,
                'is_low' => $quantity <= $minLevel,
            ];
        })
        ->sortBy([
            // Anything at or below its reorder level floats to the top, then
            // by how far below it sits.
            fn ($a, $b) => ($b['is_low'] <=> $a['is_low']),
            fn ($a, $b) => ($a['quantity'] - $a['min_stock_level']) <=> ($b['quantity'] - $b['min_stock_level']),
        ])
        ->values();

        return response()->json([
            'scoped_to_location' => $scoped,
            'summary' => [
                'items_count' => $data->count(),
                'low_stock_count' => $data->where('is_low', true)->count(),
                'total_value' => round($data->sum('total_value'), 2),
            ],
            'data' => $data,
        ]);
    }

    /**
     * Operational expense totals for the window, broken down by category.
     *
     * GET /reports/expenses?from=&to=&location_id=
     */
    public function expenses(Request $request)
    {
        [$from, $to] = $this->window($request);
        $locationId = $request->input('location_id');

        $query = Expense::query();
        if ($from) $query->where('created_at', '>=', $from);
        if ($to)   $query->where('created_at', '<', $to);
        if ($locationId && $locationId !== '' && $locationId !== 'all') {
            $query->where('location_id', $locationId);
        }

        $total = (float) $query->sum('amount');

        $byCategory = (clone $query)
            ->selectRaw('COALESCE(category, "Uncategorised") as category, COALESCE(SUM(amount), 0) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['category' => $r->category, 'total' => (float) $r->total]);

        $purchaseQuery = PurchaseOrder::query()->where('status', '!=', PurchaseOrder::CANCELLED);
        if ($from) $purchaseQuery->where('created_at', '>=', $from);
        if ($to)   $purchaseQuery->where('created_at', '<', $to);
        if ($locationId && $locationId !== '' && $locationId !== 'all') {
            $purchaseQuery->where('location_id', $locationId);
        }

        $purchaseTotal = (float) $purchaseQuery->sum('total_amount');

        return response()->json([
            'summary' => [
                'operational_expenses' => $total,
                'purchase_expenses' => $purchaseTotal,
                'total_expenses' => $total + $purchaseTotal,
            ],
            'by_category' => $byCategory,
        ]);
    }

    /**
     * Net profit = collected revenue − all expenses in the window.
     *
     * GET /reports/profit?from=&to=&location_id=
     */
    public function profit(Request $request)
    {
        [$from, $to] = $this->window($request);
        $locationId = $request->input('location_id');

        $revenue = (float) (clone $this->scope($request))
            ->where('payment_status', 'paid')
            ->sum('total');

        $expenseQuery = Expense::query();
        if ($from) $expenseQuery->where('created_at', '>=', $from);
        if ($to)   $expenseQuery->where('created_at', '<', $to);
        if ($locationId && $locationId !== '' && $locationId !== 'all') {
            $expenseQuery->where('location_id', $locationId);
        }
        $operationalExpenses = (float) $expenseQuery->sum('amount');

        $purchaseQuery = PurchaseOrder::query()->where('status', '!=', PurchaseOrder::CANCELLED);
        if ($from) $purchaseQuery->where('created_at', '>=', $from);
        if ($to)   $purchaseQuery->where('created_at', '<', $to);
        if ($locationId && $locationId !== '' && $locationId !== 'all') {
            $purchaseQuery->where('location_id', $locationId);
        }
        $purchaseExpenses = (float) $purchaseQuery->sum('total_amount');

        $totalExpenses = $operationalExpenses + $purchaseExpenses;
        $profit = $revenue - $totalExpenses;

        return response()->json([
            'summary' => [
                'revenue' => $revenue,
                'operational_expenses' => $operationalExpenses,
                'purchase_expenses' => $purchaseExpenses,
                'total_expenses' => $totalExpenses,
                'net_profit' => $profit,
                'margin_pct' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0.0,
            ],
        ]);
    }

    /**
     * Non-inventory (operational) expenses only — rent, salaries, utilities.
     * Purchase orders are excluded.
     *
     * GET /reports/non-inventory-expenses?from=&to=&location_id=
     */
    public function nonInventoryExpenses(Request $request)
    {
        [$from, $to] = $this->window($request);
        $locationId = $request->input('location_id');

        $query = Expense::query();
        if ($from) $query->where('created_at', '>=', $from);
        if ($to)   $query->where('created_at', '<', $to);
        if ($locationId && $locationId !== '' && $locationId !== 'all') {
            $query->where('location_id', $locationId);
        }

        $total = (float) $query->sum('amount');

        $byCategory = (clone $query)
            ->selectRaw('COALESCE(category, "Uncategorised") as category, COALESCE(SUM(amount), 0) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['category' => $r->category, 'total' => (float) $r->total]);

        return response()->json([
            'summary' => ['total' => $total],
            'by_category' => $byCategory,
        ]);
    }

    /**
     * Consumable inventory expenses — stock written off via the consumption log.
     * Value = quantity × inventory_item.cost_per_unit at the time of logging.
     *
     * GET /reports/consumable-expenses?from=&to=&location_id=
     */
    public function consumableExpenses(Request $request)
    {
        [$from, $to] = $this->window($request);
        $locationId = $request->input('location_id');

        $query = ConsumptionLog::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'consumption_logs.inventory_item_id')
            ->selectRaw('
                consumption_logs.inventory_item_id,
                inventory_items.title as item_name,
                inventory_items.unit,
                COALESCE(SUM(consumption_logs.quantity), 0) as total_qty,
                COALESCE(SUM(consumption_logs.quantity * COALESCE(inventory_items.cost_per_unit, 0)), 0) as total_cost
            ')
            ->groupBy('consumption_logs.inventory_item_id', 'inventory_items.title', 'inventory_items.unit');

        if ($from) $query->where('consumption_logs.consumed_at', '>=', substr($from, 0, 10));
        if ($to)   $query->where('consumption_logs.consumed_at', '<', substr($to, 0, 10));
        if ($locationId && $locationId !== '' && $locationId !== 'all') {
            $query->where('consumption_logs.location_id', $locationId);
        }

        $rows = $query->orderByDesc('total_cost')->get();
        $total = $rows->sum('total_cost');

        return response()->json([
            'summary' => ['total' => round((float) $total, 2)],
            'by_item' => $rows->map(fn ($r) => [
                'item_name' => $r->item_name,
                'unit' => $r->unit,
                'total_qty' => round((float) $r->total_qty, 3),
                'total_cost' => round((float) $r->total_cost, 2),
            ]),
        ]);
    }

    /**
     * All inventory expenses = purchase orders (countable) + consumption logs (consumable).
     *
     * GET /reports/all-inventory-expenses?from=&to=&location_id=
     */
    public function allInventoryExpenses(Request $request)
    {
        [$from, $to] = $this->window($request);
        $locationId = $request->input('location_id');

        $purchaseQuery = PurchaseOrder::query()->where('status', '!=', PurchaseOrder::CANCELLED);
        if ($from) $purchaseQuery->where('created_at', '>=', $from);
        if ($to)   $purchaseQuery->where('created_at', '<', $to);
        if ($locationId && $locationId !== '' && $locationId !== 'all') {
            $purchaseQuery->where('location_id', $locationId);
        }
        $purchaseTotal = (float) $purchaseQuery->sum('total_amount');

        $consumableQuery = ConsumptionLog::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'consumption_logs.inventory_item_id');
        if ($from) $consumableQuery->where('consumption_logs.consumed_at', '>=', substr($from, 0, 10));
        if ($to)   $consumableQuery->where('consumption_logs.consumed_at', '<', substr($to, 0, 10));
        if ($locationId && $locationId !== '' && $locationId !== 'all') {
            $consumableQuery->where('consumption_logs.location_id', $locationId);
        }
        $consumableTotal = (float) $consumableQuery->sum(DB::raw('consumption_logs.quantity * COALESCE(inventory_items.cost_per_unit, 0)'));

        return response()->json([
            'summary' => [
                'purchase_expenses' => round($purchaseTotal, 2),
                'consumable_expenses' => round($consumableTotal, 2),
                'total' => round($purchaseTotal + $consumableTotal, 2),
            ],
        ]);
    }
}
