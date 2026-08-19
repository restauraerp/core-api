<?php

namespace App\Http\Controllers;

use App\Models\AccountingLedger;
use App\Models\PartnerPayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerPayoutController extends Controller
{
    public function index(Request $request)
    {
        $query = PartnerPayout::query()->with('partner');

        if ($request->filled('partner_id')) {
            $query->where('partner_id', $request->input('partner_id'));
        }

        $query->orderByDesc('received_on')->orderByDesc('id');

        if ($request->has('nopaginate')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate(config('pagination.limit')));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'partner_id' => ['required', 'integer', $this->tenantExists('partners')],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'received_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $payout = DB::transaction(function () use ($validated) {
            $payout = PartnerPayout::create($validated);

            // Money in the door, so the books should see it. Recorded against
            // no outlet: an aggregator settles a fortnight of trading across
            // every branch in one transfer, and splitting that by outlet would
            // be inventing a breakdown the payout does not carry.
            AccountingLedger::create([
                'location_id' => null,
                'transaction_type' => 'partner_payout',
                'amount' => $validated['amount'],
                'reference_id' => $payout->id,
                'description' => 'Payout from '.$payout->partner->name
                    .(($validated['reference'] ?? null) ? " — {$validated['reference']}" : ''),
            ]);

            return $payout;
        });

        return response()->json($payout->load('partner'), 201);
    }

    public function destroy(PartnerPayout $partnerPayout)
    {
        DB::transaction(function () use ($partnerPayout) {
            AccountingLedger::where('transaction_type', 'partner_payout')
                ->where('reference_id', $partnerPayout->id)
                ->delete();

            $partnerPayout->delete();
        });

        return response()->json(null, 204);
    }
}
