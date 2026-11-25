<?php

namespace App\Http\Controllers;

use App\Models\AccountingLedger;
use App\Models\CashStorage;
use App\Models\Order;
use App\Models\PartnerPayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerPayoutController extends Controller
{
    public function index(Request $request)
    {
        if (! $request->user()->can('manage_partners')) {
            abort(403);
        }

        $query = PartnerPayout::query()->with(['partner', 'cashStorage']);

        if ($request->filled('partner_id')) {
            $query->where('partner_id', $request->input('partner_id'));
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->input('payment_method'));
        }

        if ($request->filled('from')) {
            $query->where('received_on', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('received_on', '<=', $request->input('to'));
        }

        $query->orderByDesc('received_on')->orderByDesc('id');

        if ($request->has('nopaginate')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate(config('pagination.limit')));
    }

    public function show(Request $request, PartnerPayout $partnerPayout)
    {
        if (! $request->user()->can('manage_partners')) {
            abort(403);
        }

        return response()->json($partnerPayout->load(['partner', 'cashStorage', 'orders']));
    }

    public function store(Request $request)
    {
        if (! $request->user()->can('manage_partners')) {
            abort(403);
        }

        $validated = $request->validate([
            'partner_id' => ['required', 'integer', $this->tenantExists('partners')],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'received_on' => ['required', 'date'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'cash_storage_id' => ['nullable', 'integer', $this->tenantExists('cash_storages')],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'order_ids' => ['nullable', 'array'],
            'order_ids.*' => ['integer'],
        ]);

        $orderIds = $validated['order_ids'] ?? [];
        $orders = collect();

        if (! empty($orderIds)) {
            $orders = Order::query()
                ->where('partner_id', $validated['partner_id'])
                ->whereIn('id', $orderIds)
                ->get();

            if ($orders->isEmpty()) {
                abort(422, 'No matching orders found for this partner.');
            }

            if (empty($validated['amount'])) {
                $computedNet = $orders->sum(fn ($o) => (float) $o->total - (float) ($o->partner_commission_amount ?? 0));
                $validated['amount'] = max(0.01, round($computedNet, 2));
            }
        }

        if (empty($validated['amount'])) {
            return response()->json(['message' => 'The amount field is required when no orders are selected.'], 422);
        }

        $payout = DB::transaction(function () use ($validated, $orders) {
            $payoutData = collect($validated)->except('order_ids')->toArray();
            $payoutData['orders_count'] = $orders->count();

            $payout = PartnerPayout::create($payoutData);

            if ($orders->isNotEmpty()) {
                foreach ($orders as $order) {
                    $order->update([
                        'partner_payout_id' => $payout->id,
                        'payment_status' => 'paid',
                    ]);

                    $order->payments()->create([
                        'method' => $payout->payment_method ?? 'bank_transfer',
                        'cash_storage_id' => $payout->cash_storage_id,
                        'amount' => $order->total,
                        'status' => 'completed',
                        'note' => "Settled in partner payout #{$payout->id}".($payout->note ? " — {$payout->note}" : ''),
                    ]);
                }
            }

            if ($payout->cash_storage_id) {
                CashStorage::where('id', $payout->cash_storage_id)->increment('current_balance', $payout->amount);
            }

            // Money in the door, so the books should see it.
            $storage = $payout->cashStorage;
            $methodPart = $payout->payment_method ? " [{$payout->payment_method}]" : '';
            $storagePart = $storage ? " ({$storage->name})" : '';
            $ordersPart = $payout->orders_count > 0 ? " ({$payout->orders_count} orders settled)" : '';
            $refPart = ($payout->reference) ? " — {$payout->reference}" : '';

            AccountingLedger::create([
                'location_id' => null,
                'transaction_type' => 'partner_payout',
                'amount' => $payout->amount,
                'reference_id' => $payout->id,
                'description' => "Payout from {$payout->partner->name}{$methodPart}{$storagePart}{$ordersPart}{$refPart}",
            ]);

            return $payout;
        });

        return response()->json($payout->load(['partner', 'cashStorage']), 201);
    }

    public function destroy(Request $request, PartnerPayout $partnerPayout)
    {
        if (! $request->user()->can('manage_partners')) {
            abort(403);
        }

        DB::transaction(function () use ($partnerPayout) {
            // Reset orders settled by this payout back to due status
            $orders = $partnerPayout->orders()->get();
            foreach ($orders as $order) {
                $order->payments()
                    ->where('note', 'like', "%partner payout #{$partnerPayout->id}%")
                    ->delete();

                $order->update([
                    'partner_payout_id' => null,
                    'payment_status' => 'due',
                ]);
            }

            if ($partnerPayout->cash_storage_id) {
                CashStorage::where('id', $partnerPayout->cash_storage_id)->decrement('current_balance', $partnerPayout->amount);
            }

            AccountingLedger::where('transaction_type', 'partner_payout')
                ->where('reference_id', $partnerPayout->id)
                ->delete();

            $partnerPayout->delete();
        });

        return response()->json(null, 204);
    }
}
