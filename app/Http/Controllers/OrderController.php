<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
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
        return response()->json($query->orderBy('created_at', 'desc')->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'location_id' => ['required', $this->tenantExists('locations')],
            'order_type' => 'required|string',
            'status' => 'required|string',
            'payment_status' => 'sometimes|string',
            'subtotal' => 'required|numeric',
            'tax_amount' => 'required|numeric',
            'discount_amount' => 'required|numeric',
            'delivery_charge' => 'nullable|numeric',
            'total' => 'required|numeric',
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

        $order = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $request) {
            $orderData = collect($validated)->except(['items', 'payment_method'])->toArray();
            $orderData['user_id'] = $request->user() ? $request->user()->id : null;
            
            if (!empty($validated['payment_method'])) {
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

            if (!empty($validated['payment_method'])) {
                $order->payments()->create([
                    'method' => $validated['payment_method'],
                    'amount' => $validated['total'],
                    'status' => 'completed',
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
            'tax_amount' => 'sometimes|numeric',
            'total' => 'sometimes|numeric',
            'delivery_time' => 'sometimes|nullable|date',
            'delivery_address' => 'sometimes|nullable|string',
            'latitude' => 'sometimes|nullable|numeric',
            'longitude' => 'sometimes|nullable|numeric',
        ]);

        $order->update($request->only(['status', 'payment_status', 'discount_id', 'discount_amount', 'delivery_charge', 'tax_amount', 'total', 'delivery_time', 'delivery_address', 'latitude', 'longitude']));

        if ($request->filled('payment_method')) {
            $order->update(['payment_status' => 'paid']);
            // Ensure no duplicate completed payment exists for this order
            $existingPayment = $order->payments()->where('status', 'completed')->first();
            if (!$existingPayment) {
                $order->payments()->create([
                    'method' => $validated['payment_method'],
                    'amount' => $order->total,
                    'status' => 'completed',
                ]);
            }
        }

        return response()->json($order->load(['items', 'payments']));
    }

    public function destroy(Order $order)
    {
        $order->delete();
        return response()->json(null, 204);
    }
}