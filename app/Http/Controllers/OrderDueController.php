<?php

namespace App\Http\Controllers;

use App\Models\AccountingLedger;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Letting an order leave without being paid for, and collecting it later.
 *
 * The case this exists for is a resident hotel guest charging dinner to their
 * room, and the in-house account that follows: the food goes out, the money
 * comes later, and in between the restaurant is owed something it has to be
 * able to find again.
 *
 * Kept out of OrderController deliberately. That class already runs to four
 * hundred lines and owns the order's own lifecycle - what was ordered, what
 * stage it is at, what it costs. Who owes what for it afterwards is a separate
 * question with separate rules.
 */
class OrderDueController extends Controller
{
    /**
     * Marks an order as owed rather than unpaid.
     *
     * A customer is required, and that is the point rather than a formality: a
     * debt nobody is named on cannot be collected, and "the man in the blue
     * shirt" is not a debtor. The note is where the arrangement goes - a room
     * number, a company account, who authorised it - because that is exactly
     * what otherwise lives only in the memory of whoever was on shift.
     */
    public function markDue(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'due_note' => ['required', 'string', 'max:500'],
        ], [
            'due_note.required' => 'Say what this is owed against - a room number, a company account, or who agreed to it.',
        ]);

        if ($order->payment_status === Order::PAYMENT_PAID) {
            throw ValidationException::withMessages([
                'payment_status' => 'This order is already paid for, so there is nothing owed on it.',
            ]);
        }

        if ($order->customer_id === null) {
            throw ValidationException::withMessages([
                'customer_id' => 'Attach a customer before putting an order on account - an unnamed debt cannot be collected.',
            ]);
        }

        $order->update([
            'payment_status' => Order::PAYMENT_DUE,
            'due_note' => $validated['due_note'],
        ]);

        return response()->json($this->present($order));
    }

    /**
     * Records money collected against a due order, in part or in full.
     *
     * Part payments are allowed because they happen - a guest settles half the
     * tab on Friday and the rest on Sunday. The order flips to paid the moment
     * the payments cover the total, so nothing has to remember to close it.
     */
    public function settle(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $outstanding = $order->amount_outstanding;

        if ($outstanding <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Nothing is outstanding on this order.',
            ]);
        }

        // Refused rather than silently capped: a cashier typing 5000 against a
        // 500 tab has made a mistake, and quietly recording 500 hides it.
        if ((float) $validated['amount'] > $outstanding + 0.001) {
            throw ValidationException::withMessages([
                'amount' => sprintf('Only %.2f is outstanding on this order.', $outstanding),
            ]);
        }

        $order = DB::transaction(function () use ($order, $validated) {
            $order->payments()->create([
                'method' => $validated['method'],
                'amount' => $validated['amount'],
                'status' => 'completed',
                'note' => $validated['note'] ?? null,
            ]);

            // Posted per settlement, not per order: a tab paid in three
            // instalments is three movements of money, and the books should
            // show when each arrived.
            AccountingLedger::create([
                'location_id' => $order->location_id,
                'transaction_type' => 'order_payment',
                'amount' => $validated['amount'],
                'reference_id' => $order->id,
                'description' => "Order #{$order->id} — settlement ({$validated['method']})",
            ]);

            $order->refresh();

            if ($order->amount_outstanding <= 0) {
                $order->update(['payment_status' => Order::PAYMENT_PAID]);
            }

            return $order->refresh();
        });

        return response()->json($this->present($order));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Order $order): array
    {
        return $order->load(['items.product', 'payments', 'customer'])->toArray() + [
            'amount_paid' => $order->amount_paid,
            'amount_outstanding' => $order->amount_outstanding,
        ];
    }
}
