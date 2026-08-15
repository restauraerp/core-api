<?php

namespace App\Http\Controllers;

use App\Models\AccountingLedger;
use App\Models\Order;
use App\Support\Inventory\SellableInventory;
use App\Support\Orders\KitchenLead;
use App\Support\Orders\OrderFlow;
use App\Support\Sales\TaxCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(
        private readonly SellableInventory $sellable,
        private readonly OrderFlow $flow,
        private readonly KitchenLead $lead,
    ) {}

    public function index(Request $request)
    {
        $query = Order::with(['items.product.images', 'payments', 'customer', 'table']);

        if ($request->has('from')) {
            $query->where('created_at', '>=', $request->from);
        }
        if ($request->has('to')) {
            $query->where('created_at', '<=', $request->to);
        }

        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        if ($request->has('statuses')) {
            $query->whereIn('status', is_array($request->statuses) ? $request->statuses : explode(',', $request->statuses));
        }

        // Live floor view: everything still needing attention.
        if ($request->has('active_only')) {
            $query->active();
        }

        // What the kitchen has to start now: orders due inside the lead window
        // (or already overdue), including the ones with no time on them, which
        // are wanted as soon as they can be made.
        //
        // `due_soon` uses the restaurant's own lead time; `due_within=90`
        // overrides it for a caller that wants a different horizon.
        if ($request->has('due_soon') || $request->filled('due_within')) {
            $window = $request->filled('due_within')
                ? max(0, $request->integer('due_within'))
                : $this->lead->minutes();

            $query->where(function ($due) use ($window) {
                $due->whereNull('delivery_time')
                    ->orWhere('delivery_time', '<=', now()->addMinutes($window));
            });
        }

        // The Completed tab. Deliberately applies no order_type filter - it
        // lists finished orders of every type together.
        //
        // Note this is never combined with `nopaginate` in practice: completed
        // orders accumulate forever (a demo tenant already carries ~50k), and
        // this query eager-loads items, products and images per row.
        if ($request->has('completed_only')) {
            $query->completed();
        }

        if ($request->has('nopaginate')) {
            return response()->json($query->orderBy('created_at', 'desc')->get());
        }

        return response()->json($query->orderBy('created_at', 'desc')->paginate((int) env('PAGINATION_LIMIT', 15)));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'location_id' => ['required', $this->tenantExists('locations')],
            'order_type' => ['required', 'string', Rule::in([OrderFlow::DINE_IN, OrderFlow::TAKEAWAY, OrderFlow::DELIVERY, OrderFlow::CATERING])],
            // Accepted for backwards compatibility and then ignored, like the
            // money below: where an order starts depends on what is on it and
            // when it is due, which is not the till's call to make.
            'status' => 'sometimes|string',
            'payment_status' => 'sometimes|string',
            'subtotal' => 'required|numeric',
            // Accepted for backwards compatibility and then ignored - both are
            // recomputed below from the tenant's tax rules. A till that posts
            // its own tax is posting a number it chose.
            'tax_amount' => 'sometimes|numeric',
            'discount_amount' => 'required|numeric',
            'delivery_charge' => 'nullable|numeric',
            'total' => 'sometimes|numeric',
            'table_id' => ['nullable', 'integer', $this->tenantExists('tables')],
            'hall_id' => ['nullable', 'integer', $this->tenantExists('halls')],
            'customer_id' => ['nullable', 'integer', $this->tenantExists('customers')],
            'discount_id' => ['nullable', 'integer', $this->tenantExists('discounts')],
            'payment_method' => 'nullable|string',
            'delivery_time' => 'nullable|date',
            'delivery_address' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'items' => 'required|array',
            'items.*.product_id' => ['required', $this->tenantExists('products')],
            'items.*.qty' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric',
            'items.*.notes' => 'nullable|string|max:500',
        ]);

        // Tax comes from the restaurant's own rules, not from the request. With
        // no active rule the sale carries no tax at all, which is the seeded
        // default until an owner sets a rate that is right for them.
        $taxable = max(0, (float) $validated['subtotal'] - (float) $validated['discount_amount']);
        $validated['tax_amount'] = TaxCalculator::on($taxable);
        $validated['total'] = round($taxable + $validated['tax_amount'] + (float) ($validated['delivery_charge'] ?? 0), 2);

        // Where the order opens: waiting if it is not due yet, the kitchen if
        // something on it has to be prepared, otherwise straight to ready.
        $deliveryTime = ! empty($validated['delivery_time']) ? Carbon::parse($validated['delivery_time']) : null;

        $validated['needs_cooking'] = $this->flow->productsNeedCooking(
            array_column($validated['items'], 'product_id'),
        );
        $validated['status'] = $this->flow->openingStatus($deliveryTime, $validated['needs_cooking']);

        $order = DB::transaction(function () use ($validated, $request) {
            $orderData = collect($validated)->except(['items', 'payment_method'])->toArray();
            $orderData['user_id'] = $request->user() ? $request->user()->id : null;

            if (! empty($validated['payment_method'])) {
                $orderData['payment_status'] = 'paid';
            }

            $order = Order::create($orderData);

            foreach ($validated['items'] as $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['qty'],
                    'price' => $item['price'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            // Anything sold as bought - a bottle, a packet - leaves the shelf
            // it was sold from. Cooked dishes are made of ingredients this
            // cannot know about and are left to recipes.
            $this->sellable->deductForOrder($order);

            if (! empty($validated['payment_method'])) {
                $order->payments()->create([
                    'method' => $validated['payment_method'],
                    'amount' => $validated['total'],
                    'status' => 'completed',
                ]);

                AccountingLedger::create([
                    'location_id'      => $order->location_id,
                    'transaction_type' => 'order_payment',
                    'amount'           => $order->total,
                    'reference_id'     => $order->id,
                    'description'      => "Order #{$order->id} — {$validated['payment_method']}",
                ]);
            }

            return $order->load(['items', 'payments']);
        });

        return response()->json($order, 201);
    }

    public function show(Order $order)
    {
        return response()->json($order->load(['items.product', 'payments', 'table', 'customer']));
    }

    public function update(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status' => 'sometimes|string',
            'payment_status' => 'sometimes|string',
            'payment_method' => 'sometimes|string',
            'discount_id' => ['sometimes', 'nullable', 'integer', $this->tenantExists('discounts')],
            'discount_amount' => 'sometimes|numeric',
            'delivery_charge' => 'sometimes|numeric',
            // Accepted and ignored, as in store(): recomputed below when the
            // money actually changes.
            'tax_amount' => 'sometimes|numeric',
            'total' => 'sometimes|numeric',
            'delivery_time' => 'sometimes|nullable|date',
            'delivery_address' => 'sometimes|nullable|string',
            'latitude' => 'sometimes|nullable|numeric',
            'longitude' => 'sometimes|nullable|numeric',
        ]);

        if (array_key_exists('status', $validated)) {
            $requested = $this->flow->normalise($validated['status']);

            // One step at a time. Jumping stages would leave an order marked
            // delivered that nobody packed, and no report could tell afterwards
            // which of the two actually happened.
            if (! $this->flow->canTransition((string) $order->order_type, $order->status, $requested, (bool) $order->needs_cooking)) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'A %s order cannot go from %s to %s. Next: %s.',
                        str_replace('_', ' ', (string) $order->order_type),
                        $this->flow->label($order->status),
                        $this->flow->label($requested),
                        implode(', ', array_map(
                            fn (string $status) => $this->flow->label($status),
                            $this->flow->next((string) $order->order_type, $order->status, (bool) $order->needs_cooking),
                        )) ?: 'nothing, it is finished',
                    ),
                ]);
            }

            $validated['status'] = $requested;
        }

        $payload = collect($validated)
            ->except(['payment_method', 'tax_amount', 'total'])
            ->toArray();

        // Only re-price when the sale itself is edited. Marking an old order
        // delivered must not quietly rewrite the tax it was billed at - orders
        // taken before tax was driven by the rules keep the figure they were
        // charged, which is what the customer actually paid.
        if ($request->hasAny(['discount_amount', 'delivery_charge'])) {
            $discount = (float) ($payload['discount_amount'] ?? $order->discount_amount);
            $delivery = (float) ($payload['delivery_charge'] ?? $order->delivery_charge);
            $taxable = max(0, (float) $order->subtotal - $discount);

            $payload['tax_amount'] = TaxCalculator::on($taxable);
            $payload['total'] = round($taxable + $payload['tax_amount'] + $delivery, 2);
        }

        $wasCancelled = $this->isCancelled($order->getOriginal('status'));

        $order->update($payload);

        // A cancelled sale did not happen, so the bottle is back on the shelf -
        // and un-cancelling takes it off again.
        if (! $wasCancelled && $this->isCancelled($order->status)) {
            $this->sellable->restoreForOrder($order);
        } elseif ($wasCancelled && ! $this->isCancelled($order->status)) {
            $this->sellable->deductForOrder($order);
        }

        if ($request->filled('payment_method')) {
            $order->update(['payment_status' => 'paid']);
            // Ensure no duplicate completed payment exists for this order
            $existingPayment = $order->payments()->where('status', 'completed')->first();
            if (! $existingPayment) {
                $order->payments()->create([
                    'method' => $validated['payment_method'],
                    'amount' => $order->total,
                    'status' => 'completed',
                ]);

                // Post to the accounting ledger so the sale appears in financial records.
                // Only create one entry per order — the duplicate guard above ensures
                // we are in the first-payment branch before we reach this point.
                $alreadyPosted = AccountingLedger::where('transaction_type', 'order_payment')
                    ->where('reference_id', $order->id)
                    ->exists();

                if (! $alreadyPosted) {
                    AccountingLedger::create([
                        'location_id'      => $order->location_id,
                        'transaction_type' => 'order_payment',
                        'amount'           => $order->total,
                        'reference_id'     => $order->id,
                        'description'      => "Order #{$order->id} — {$validated['payment_method']}",
                    ]);
                }
            }
        }

        return response()->json($order->load(['items', 'payments']));
    }

    public function destroy(Order $order)
    {
        // A deleted order never happened; whatever it sold goes back on the
        // shelf, unless it was already cancelled and put back then.
        if (! $this->isCancelled($order->status)) {
            $this->sellable->restoreForOrder($order);
        }

        $order->delete();

        return response()->json(null, 204);
    }

    private function isCancelled(?string $status): bool
    {
        return $this->flow->normalise($status) === OrderFlow::CANCELLED;
    }
}
