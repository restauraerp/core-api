<?php

namespace App\Http\Controllers;

use App\Models\Image;
use App\Models\PurchaseOrder;
use App\Support\Inventory\PurchaseOrderStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Purchase orders: what was bought, from whom, for which outlet, at what price -
 * and the stock that arrived with it.
 *
 * An order is written as one document: the header, its line rows and its
 * receipt images all arrive in a single request and are saved in one
 * transaction, because a header with no lines is not a purchase and half-saved
 * lines would mean half-delivered stock.
 *
 * Creating an order puts its quantities into inventory straight away (see
 * PurchaseOrderStock). Every later edit re-states them, and cancelling or
 * deleting the order takes them back out.
 */
class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderStock $stock) {}

    /** Everything the list screen shows, without a query per row. */
    private const RELATIONS = ['supplier', 'location', 'creator', 'items.inventoryItem', 'receipts'];

    public function index(Request $request)
    {
        $query = PurchaseOrder::with(self::RELATIONS)->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->integer('location_id'));
        }

        if ($request->has('nopaginate')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate(config('pagination.limit')));
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        $order = DB::transaction(function () use ($request, $validated) {
            $order = PurchaseOrder::create([
                'supplier_id' => $validated['supplier_id'],
                'location_id' => $validated['location_id'],
                'created_by' => $validated['created_by'] ?? $request->user()?->getKey(),
                'status' => $validated['status'] ?? 'received',
                'notes' => $validated['notes'] ?? null,
            ]);

            $this->replaceItems($order, $validated['items']);
            $this->storeReceipts($order, $request);

            // The delivery is in the building the moment the order is written -
            // this is the whole point of recording it.
            $this->stock->apply($order);
            $this->stock->refreshCosts(array_column($validated['items'], 'inventory_item_id'));

            return $order;
        });

        return response()->json($order->load(self::RELATIONS), 201);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        return response()->json($purchaseOrder->load(self::RELATIONS));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $validated = $request->validate($this->rules(updating: true));

        DB::transaction(function () use ($request, $validated, $purchaseOrder) {
            // Both the items this order used to name and the ones it names now
            // need their cost re-priced: dropping a line can leave an item
            // priced from a delivery that no longer exists.
            $touchedItems = $purchaseOrder->items()->pluck('inventory_item_id')->all();

            // Out of inventory first, at the quantities and outlet it currently
            // has - after the update those values are gone and the reversal
            // would be wrong.
            $this->stock->reverse($purchaseOrder);

            $purchaseOrder->update(array_intersect_key($validated, array_flip([
                'supplier_id', 'location_id', 'created_by', 'status', 'notes',
            ])));

            if (array_key_exists('items', $validated)) {
                $this->replaceItems($purchaseOrder, $validated['items']);
                $touchedItems = array_merge($touchedItems, array_column($validated['items'], 'inventory_item_id'));
            }

            $this->deleteReceipts($purchaseOrder, $validated['remove_receipt_ids'] ?? []);
            $this->storeReceipts($purchaseOrder, $request);

            $this->stock->apply($purchaseOrder->refresh());
            $this->stock->refreshCosts($touchedItems);
        });

        return response()->json($purchaseOrder->load(self::RELATIONS));
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        DB::transaction(function () use ($purchaseOrder) {
            $touchedItems = $purchaseOrder->items()->pluck('inventory_item_id')->all();

            // A deleted order never happened, so neither did its delivery.
            $this->stock->reverse($purchaseOrder);
            // One by one, so each receipt's file leaves the disk with its row.
            $purchaseOrder->receipts()->get()->each->delete();
            $purchaseOrder->delete();

            // Prices fall back to whatever the previous delivery charged.
            $this->stock->refreshCosts($touchedItems);
        });

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'supplier_id' => [$required, 'integer', $this->tenantExists('suppliers')],
            'location_id' => [$required, 'integer', $this->tenantExists('locations')],
            'created_by' => ['nullable', 'integer', $this->tenantExists('users')],
            'status' => ['nullable', Rule::in(PurchaseOrder::STATUSES)],
            'notes' => 'nullable|string|max:2000',

            // An order with no lines buys nothing, so at least one is required.
            'items' => [$required, 'array', 'min:1'],
            'items.*.inventory_item_id' => ['required', 'integer', $this->tenantExists('inventory_items')],
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.price' => 'required|numeric|min:0',

            'receipts' => 'nullable|array|max:10',
            'receipts.*' => 'image|max:5120',
            'remove_receipt_ids' => 'nullable|array',
            'remove_receipt_ids.*' => 'integer',
        ];
    }

    /**
     * Lines are replaced wholesale rather than diffed: the client sends the
     * order as it should now read, and a row the user deleted has no id to
     * match on.
     *
     * @param  array<int, array{inventory_item_id: int, quantity: numeric, price: numeric}>  $items
     */
    private function replaceItems(PurchaseOrder $order, array $items): void
    {
        $order->items()->delete();

        $total = 0.0;

        foreach ($items as $item) {
            $order->items()->create([
                'inventory_item_id' => $item['inventory_item_id'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
            ]);

            $total += (float) $item['quantity'] * (float) $item['price'];
        }

        // Never taken from the request: a total that disagrees with the lines
        // is a total nobody can audit.
        $order->forceFill(['total_amount' => round($total, 2)])->save();
    }

    private function storeReceipts(PurchaseOrder $order, Request $request): void
    {
        foreach ($request->file('receipts', []) as $receipt) {
            $order->receipts()->create([
                'url' => $receipt->store('receipts', 'public'),
                'type' => 'receipt',
            ]);
        }
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function deleteReceipts(PurchaseOrder $order, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        // Scoped through the relation, so an id belonging to another order -
        // or another tenant - deletes nothing.
        $order->receipts()->whereIn((new Image)->getTable().'.id', $ids)->get()->each->delete();
    }
}
