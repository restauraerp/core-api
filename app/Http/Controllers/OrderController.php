<?php

namespace App\Http\Controllers;

use App\Models\AccountingLedger;
use App\Models\Order;
use App\Models\Partner;
use App\Models\Payment;
use App\Support\Inventory\SellableInventory;
use App\Support\Orders\KitchenLead;
use App\Support\Orders\OrderFlow;
use App\Support\Orders\TokenNumber;
use App\Models\Discount;
use App\Support\Sales\DiscountCalculator;
use App\Support\Sales\PartnerCommission;
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
        private readonly TokenNumber $tokens,
    ) {}

    public function index(Request $request)
    {
        $query = Order::with(['items.product.images', 'items.product.comboItems', 'payments', 'customer', 'table', 'partner']);

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

        // The Due tab: finished or not, this is money the restaurant is owed
        // and has agreed to collect later. Separate from `active_only`, which
        // deliberately no longer shows them - see Order::scopeActive().
        if ($request->has('due_only')) {
            $query->due()->whereNull('partner_id');
        }

        if ($request->has('partner_only')) {
            $query->whereNotNull('partner_id');
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

        // Completed orders can be grouped by how they were paid - "show me
        // everything that came through bKash yesterday" is the question asked
        // when the till is being reconciled against a mobile-money statement.
        if ($request->filled('payment_method')) {
            $query->whereHas('payments', fn ($payment) => $payment->where('method', $request->input('payment_method')));
        }

        // Ordered by payment method, then newest first within each - so the
        // cash sits together and the cards sit together.
        //
        // Ordered by a subquery rather than a join. scopeCompleted() writes its
        // status conditions unqualified and is shared with the Completed tab,
        // so joining a table that also has a `status` column makes those
        // clauses ambiguous and the query dies. An order can also carry several
        // payments, and a join would list it once per payment.
        if ($request->input('sort') === 'payment_method') {
            $query->orderBy(
                Payment::query()
                    ->select('method')
                    ->whereColumn('payments.order_id', 'orders.id')
                    ->orderBy('id')
                    ->limit(1)
            )->orderByDesc('created_at');

            if ($request->has('nopaginate')) {
                return response()->json($query->get());
            }

            return response()->json($query->paginate(config('pagination.limit')));
        }

        if ($request->has('nopaginate')) {
            return response()->json($query->orderBy('created_at', 'desc')->get());
        }

        return response()->json($query->orderBy('created_at', 'desc')->paginate(config('pagination.limit')));
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
            // Who to credit for the sale, which is not necessarily who rang
            // it up - tills are often shared logins. user_id keeps recording
            // the account that created the order; this records the employee.
            'served_by_user_id' => ['nullable', 'integer', $this->tenantExists('users')],
            // The third party that sent this order in, if any. Its commission
            // is worked out server-side from the partner's own rate.
            'partner_id' => ['nullable', 'integer', $this->tenantExists('partners')],
            'payment_method' => 'nullable|string',
            // Why this payment looks the way it does - a bKash transaction id,
            // a card's last four, which guest paid for a shared table.
            'payment_note' => 'nullable|string|max:500',
            'delivery_time' => 'nullable|date',
            'delivery_address' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'items' => 'required|array',
            'items.*.product_id' => ['required', $this->tenantExists('products')],
            'items.*.qty' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric',
            'items.*.notes' => 'nullable|string|max:500',
            // Per-item reductions - "the steak came out cold, take 200 off it".
            'items.*.discount_type' => ['nullable', Rule::in([DiscountCalculator::FLAT, DiscountCalculator::PERCENT])],
            'items.*.discount_value' => 'nullable|numeric|min:0',
            // And one on the whole bill, on top of any coupon.
            'discount_type' => ['nullable', Rule::in([DiscountCalculator::FLAT, DiscountCalculator::PERCENT])],
            'discount_value' => 'nullable|numeric|min:0',
            'discount_reason' => 'nullable|string|max:255',
        ]);

        // Tax comes from the restaurant's own rules, not from the request. With
        // no active rule the sale carries no tax at all, which is the seeded
        // default until an owner sets a rate that is right for them.
        // Every reduction, worked out here from the lines and the tenant's own
        // coupon record. subtotal and discount_amount are still accepted from
        // the till for backwards compatibility and then overwritten, exactly as
        // tax_amount is: a client that prices its own discount can price any
        // discount, and the figure it posts is what reporting and the books
        // would then be built on.
        $coupon = ! empty($validated['discount_id']) ? Discount::find($validated['discount_id']) : null;

        $money = DiscountCalculator::forOrder(
            $validated['items'],
            $coupon,
            $validated['discount_type'] ?? null,
            $validated['discount_value'] ?? null,
            $validated['discount_amount'] ?? null,
        );

        $validated['subtotal'] = $money['subtotal'];
        $validated['discount_amount'] = $money['discount_amount'];

        $taxable = max(0, $money['subtotal'] - $money['discount_amount']);
        $validated['tax_amount'] = TaxCalculator::on($taxable);
        $validated['total'] = round($taxable + $validated['tax_amount'] + (float) ($validated['delivery_charge'] ?? 0), 2);

        // A partner's cut, priced from the partner's own rate rather than from
        // anything the till sent, and stamped onto the order so a later rate
        // change cannot restate what this sale earned.
        if (! empty($validated['partner_id'])) {
            $partner = Partner::findOrFail($validated['partner_id']);
            $commission = PartnerCommission::on($partner, (float) $validated['total']);

            $validated['partner_commission_rate'] = $commission['rate'];
            $validated['partner_commission_amount'] = $commission['amount'];
        }

        // Where the order opens: waiting if it is not due yet, the kitchen if
        // something on it has to be prepared, otherwise straight to ready.
        $deliveryTime = ! empty($validated['delivery_time']) ? Carbon::parse($validated['delivery_time']) : null;

        $validated['needs_cooking'] = $this->flow->productsNeedCooking(
            array_column($validated['items'], 'product_id'),
        );
        $validated['status'] = $this->flow->openingStatus($deliveryTime, $validated['needs_cooking']);

        $order = DB::transaction(function () use ($validated, $request, $money) {
            $orderData = collect($validated)->except(['items', 'payment_method', 'payment_note'])->toArray();
            $orderData['user_id'] = $request->user() ? $request->user()->id : null;

            if (! empty($validated['payment_method'])) {
                $orderData['payment_status'] = 'paid';
            }

            // The counter number, claimed inside the transaction so a failed
            // order gives its token back rather than leaving a gap in the day.
            $token = $this->tokens->allocate((int) $validated['location_id']);
            $orderData['business_date'] = $token['business_date'];
            $orderData['token_number'] = $token['token_number'];

            $order = Order::create($orderData);

            foreach ($validated['items'] as $index => $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['qty'],
                    'price' => $item['price'],
                    'discount_type' => $item['discount_type'] ?? null,
                    'discount_value' => $item['discount_value'] ?? null,
                    // Stored, not recomputed on read: a receipt reprinted next
                    // year has to show what was actually taken off.
                    'discount_amount' => $money['lines'][$index]['discount_amount'] ?? 0,
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
        return response()->json($order->load(['items.product.comboItems', 'payments', 'table', 'customer']));
    }

    public function update(Request $request, Order $order)
    {
        if ($request->has('items')) {
            if (! $request->user()->can('edit_order')) {
                abort(403, 'You do not have permission to edit orders.');
            }
            return $this->fullEdit($request, $order);
        }

        return $this->partialUpdate($request, $order);
    }

    private function fullEdit(Request $request, Order $order)
    {
        if ($order->payment_status === 'paid') {
            abort(422, 'Paid orders cannot be edited.');
        }

        $validated = $request->validate([
            'order_type' => ['sometimes', 'string', Rule::in([OrderFlow::DINE_IN, OrderFlow::TAKEAWAY, OrderFlow::DELIVERY, OrderFlow::CATERING])],
            'table_id' => ['nullable', 'integer', $this->tenantExists('tables')],
            'customer_id' => ['nullable', 'integer', $this->tenantExists('customers')],
            'discount_id' => ['nullable', 'integer', $this->tenantExists('discounts')],
            // Who to credit for the sale, which is not necessarily who rang
            // it up - tills are often shared logins. user_id keeps recording
            // the account that created the order; this records the employee.
            'served_by_user_id' => ['nullable', 'integer', $this->tenantExists('users')],
            'subtotal' => 'required|numeric',
            'discount_amount' => 'required|numeric',
            'delivery_charge' => 'nullable|numeric',
            'delivery_time' => 'nullable|date',
            'delivery_address' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'items' => 'required|array|min:1',
            'items.*.product_id' => ['required', $this->tenantExists('products')],
            'items.*.qty' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric',
            'items.*.notes' => 'nullable|string|max:500',
            'items.*.discount_type' => ['nullable', Rule::in([DiscountCalculator::FLAT, DiscountCalculator::PERCENT])],
            'items.*.discount_value' => 'nullable|numeric|min:0',
            'discount_type' => ['nullable', Rule::in([DiscountCalculator::FLAT, DiscountCalculator::PERCENT])],
            'discount_value' => 'nullable|numeric|min:0',
            'discount_reason' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($validated, $order) {
            $this->sellable->restoreForOrder($order);

            // Same rule as store(): the lines decide, not the posted totals.
            $coupon = ! empty($validated['discount_id']) ? Discount::find($validated['discount_id']) : null;

            $money = DiscountCalculator::forOrder(
                $validated['items'],
                $coupon,
                $validated['discount_type'] ?? null,
                $validated['discount_value'] ?? null,
                $validated['discount_amount'] ?? null,
            );

            $taxable = max(0, $money['subtotal'] - $money['discount_amount']);
            $taxAmount = TaxCalculator::on($taxable);
            $delivery = (float) ($validated['delivery_charge'] ?? 0);

            $orderType = $validated['order_type'] ?? $order->order_type;

            $needsCooking = $this->flow->productsNeedCooking(
                array_column($validated['items'], 'product_id'),
            );

            $order->update([
                'order_type' => $orderType,
                'table_id' => $validated['table_id'] ?? null,
                'customer_id' => $validated['customer_id'] ?? null,
                'discount_id' => $validated['discount_id'] ?? null,
                'subtotal' => $money['subtotal'],
                'discount_amount' => $money['discount_amount'],
                'discount_type' => $validated['discount_type'] ?? null,
                'discount_value' => $validated['discount_value'] ?? null,
                'discount_reason' => $validated['discount_reason'] ?? null,
                'delivery_charge' => $delivery,
                'tax_amount' => $taxAmount,
                'total' => round($taxable + $taxAmount + $delivery, 2),
                'delivery_time' => $validated['delivery_time'] ?? null,
                'delivery_address' => $validated['delivery_address'] ?? null,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'needs_cooking' => $needsCooking,
            ]);

            $order->items()->delete();

            foreach ($validated['items'] as $index => $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['qty'],
                    'price' => $item['price'],
                    'discount_type' => $item['discount_type'] ?? null,
                    'discount_value' => $item['discount_value'] ?? null,
                    'discount_amount' => $money['lines'][$index]['discount_amount'] ?? 0,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            $this->sellable->deductForOrder($order->refresh());

            return response()->json($order->load(['items.product', 'payments', 'customer', 'table']));
        });
    }

    private function partialUpdate(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status' => 'sometimes|string',
            'payment_status' => 'sometimes|string',
            'payment_method' => 'sometimes|string',
            'payment_note' => 'sometimes|nullable|string|max:500',
            'discount_id' => ['sometimes', 'nullable', 'integer', $this->tenantExists('discounts')],
            'discount_amount' => 'sometimes|numeric',
            'delivery_charge' => 'sometimes|numeric',
            'tax_amount' => 'sometimes|numeric',
            'total' => 'sometimes|numeric',
            'delivery_time' => 'sometimes|nullable|date',
            'delivery_address' => 'sometimes|nullable|string',
            'latitude' => 'sometimes|nullable|numeric',
            'longitude' => 'sometimes|nullable|numeric',
        ]);

        if (array_key_exists('status', $validated)) {
            $requested = $this->flow->normalise($validated['status']);

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
            ->except(['payment_method', 'payment_note', 'tax_amount', 'total'])
            ->toArray();

        if ($request->filled('payment_method')) {
            // Taking payment records how the money arrived. It does not reprice
            // the bill, and a till that posts totals alongside a payment method
            // is posting figures it worked out itself - which is how a bill
            // discounted at the POS came to be charged at full price and then
            // stored that way, the discount gone from the order as well.
            // Changing what is owed is an edit to the order, made on its own.
            //
            // Dropped rather than refused, so a till still on the old build
            // stops damaging orders the day this ships instead of losing the
            // ability to take payment at all.
            unset($payload['discount_id'], $payload['discount_amount'], $payload['delivery_charge']);
        } elseif ($request->hasAny(['discount_amount', 'delivery_charge'])) {
            $discount = (float) ($payload['discount_amount'] ?? $order->discount_amount);
            $delivery = (float) ($payload['delivery_charge'] ?? $order->delivery_charge);
            $taxable = max(0, (float) $order->subtotal - $discount);

            $payload['tax_amount'] = TaxCalculator::on($taxable);
            $payload['total'] = round($taxable + $payload['tax_amount'] + $delivery, 2);
        }

        $wasCancelled = $this->isCancelled($order->getOriginal('status'));

        $order->update($payload);

        if (! $wasCancelled && $this->isCancelled($order->status)) {
            $this->sellable->restoreForOrder($order);
        } elseif ($wasCancelled && ! $this->isCancelled($order->status)) {
            $this->sellable->deductForOrder($order);
        }

        if ($request->filled('payment_method')) {
            $order->update(['payment_status' => 'paid']);
            $existingPayment = $order->payments()->where('status', 'completed')->first();
            if (! $existingPayment) {
                $order->payments()->create([
                    'method' => $validated['payment_method'],
                    'amount' => $order->total,
                    'status' => 'completed',
                    'note' => $validated['payment_note'] ?? null,
                ]);

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

    public function destroy(Request $request, Order $order)
    {
        // Cancelling an order is an edit to what the till already rang up, so
        // it rides on the same permission as "Edit in POS" - a role that
        // cannot change an order cannot void it either.
        if (! $request->user()->can('edit_order')) {
            abort(403, 'You do not have permission to cancel orders.');
        }

        // A deleted order never happened; whatever it sold goes back on the
        // shelf, unless it was already cancelled and put back then.
        if (! $this->isCancelled($order->status)) {
            $this->sellable->restoreForOrder($order);
        }

        $order->delete();

        return response()->json(null, 204);
    }

    public function trash(Request $request, Order $order)
    {
        if (! $request->user()->hasRole(['restaurant_admin', 'super_admin'])) {
            abort(403, 'Only administrators can trash orders.');
        }

        if (! $this->isCancelled($order->status)) {
            $this->sellable->restoreForOrder($order);
        }

        AccountingLedger::where('transaction_type', 'order_payment')
            ->where('reference_id', $order->id)
            ->delete();

        $order->update(['trashed_by' => $request->user()->id]);
        $order->delete();

        return response()->json(null, 204);
    }

    public function restore(Request $request, int $id)
    {
        if (! $request->user()->hasRole(['restaurant_admin', 'super_admin'])) {
            abort(403, 'Only administrators can restore orders.');
        }

        $order = Order::onlyTrashed()->findOrFail($id);

        $order->restore();
        $order->update(['trashed_by' => null]);

        if (! $this->isCancelled($order->status)) {
            $this->sellable->deductForOrder($order);
        }

        if ($order->payment_status === 'paid') {
            $alreadyPosted = AccountingLedger::where('transaction_type', 'order_payment')
                ->where('reference_id', $order->id)
                ->exists();

            if (! $alreadyPosted) {
                $payment = $order->payments()->where('status', 'completed')->first();
                AccountingLedger::create([
                    'location_id'      => $order->location_id,
                    'transaction_type' => 'order_payment',
                    'amount'           => $order->total,
                    'reference_id'     => $order->id,
                    'description'      => "Order #{$order->id} — " . ($payment?->method ?? 'restored'),
                ]);
            }
        }

        return response()->json($order->load(['items.product', 'payments', 'customer', 'table']));
    }

    public function trashed(Request $request)
    {
        if (! $request->user()->hasRole(['restaurant_admin', 'super_admin'])) {
            abort(403, 'Only administrators can view trashed orders.');
        }

        $query = Order::onlyTrashed()
            ->with(['items.product.images', 'payments', 'customer', 'table', 'partner', 'trashedByUser']);

        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        return response()->json(
            $query->orderBy('deleted_at', 'desc')
                ->paginate(config('pagination.limit'))
        );
    }

    private function isCancelled(?string $status): bool
    {
        return $this->flow->normalise($status) === OrderFlow::CANCELLED;
    }
}
