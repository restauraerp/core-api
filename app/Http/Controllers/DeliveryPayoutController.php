<?php

namespace App\Http\Controllers;

use App\Models\AccountingHeader;
use App\Models\DeliveryPayout;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Paying delivery charges back out to riders and couriers.
 *
 * A payout is booked as an ordinary Expense under a "Delivery Payouts" header,
 * so it comes off net profit in the Profit report and shows up in the
 * accounting reports with no special handling. The payout row itself keeps the
 * rider, outlet and reference, and is what the Delivery Charges report reads to
 * show how much is still to be paid out.
 */
class DeliveryPayoutController extends Controller
{
    public const HEADER_NAME = 'Delivery Payouts';

    public function index(Request $request)
    {
        if (! $request->user()->can('view_delivery')) {
            abort(403);
        }

        $query = DeliveryPayout::query()->with(['rider:id,name', 'location:id,name']);

        if ($request->filled('from')) {
            $query->where('paid_on', '>=', substr((string) $request->input('from'), 0, 10));
        }
        if ($request->filled('to')) {
            $query->where('paid_on', '<', substr((string) $request->input('to'), 0, 10));
        }
        $locationId = $request->input('location_id');
        if ($locationId !== null && $locationId !== '' && $locationId !== 'all') {
            $query->where('location_id', $locationId);
        }

        $query->orderByDesc('paid_on')->orderByDesc('id');

        if ($request->has('nopaginate')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate(config('pagination.limit')));
    }

    public function store(Request $request)
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_on' => ['required', 'date'],
            'rider_id' => ['nullable', 'integer', $this->tenantExists('users')],
            'location_id' => ['nullable', 'integer', $this->tenantExists('locations')],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $payout = DB::transaction(function () use ($validated, $request) {
            $header = AccountingHeader::firstOrCreate(
                ['name' => self::HEADER_NAME, 'type' => 'expense'],
                ['description' => 'Delivery charges paid out to riders and couriers.', 'is_active' => true],
            );

            $expense = new Expense([
                'location_id' => $validated['location_id'] ?? null,
                'header_id' => $header->id,
                'category' => self::HEADER_NAME,
                'amount' => $validated['amount'],
                'logged_by' => $request->user()->id,
            ]);
            // Dated to the day it was paid, not the day it was typed in, so it
            // falls in the right period of the Profit and expense reports.
            $expense->created_at = Carbon::parse($validated['paid_on'].' '.now()->format('H:i:s'));
            $expense->save();

            return DeliveryPayout::create([...$validated, 'expense_id' => $expense->id]);
        });

        return response()->json($payout->load(['rider:id,name', 'location:id,name']), 201);
    }

    /** Removes a payout and the expense it booked, so the books stay in step. */
    public function destroy(Request $request, DeliveryPayout $deliveryPayout)
    {
        $this->authorizeManage($request);

        DB::transaction(function () use ($deliveryPayout) {
            if ($deliveryPayout->expense_id) {
                Expense::whereKey($deliveryPayout->expense_id)->delete();
            }
            $deliveryPayout->delete();
        });

        return response()->json(null, 204);
    }

    /** Recording money going out needs both the delivery module and expense rights. */
    private function authorizeManage(Request $request): void
    {
        $user = $request->user();

        if (! $user->can('view_delivery') || ! $user->can('manage_expenses')) {
            abort(403, 'You need delivery access and permission to manage expenses to record a payout.');
        }
    }
}
