<?php

namespace App\Http\Controllers;

use App\Models\CashStorage;
use App\Services\CashStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CashStorageController extends Controller
{
    public function __construct(private CashStorageService $storageService) {}

    public function index(Request $request): JsonResponse
    {
        // Auto-seed defaults if tenant has none yet
        if ($request->user()?->tenant_id) {
            $this->storageService->seedDefaultsForTenant($request->user()->tenant_id);
        }

        $query = CashStorage::query()->with('location');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('location_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('location_id', $request->input('location_id'))
                    ->orWhereNull('location_id');
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $query->withCount('payments')
            ->orderBy('type')
            ->orderBy('name');

        if ($request->has('nopaginate')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate(config('pagination.limit')));
    }

    public function summary(Request $request): JsonResponse
    {
        if ($request->user()?->tenant_id) {
            $this->storageService->seedDefaultsForTenant($request->user()->tenant_id);
        }

        $storages = CashStorage::query()->active()->get();

        $cashTotal = (float) $storages->where('type', CashStorage::TYPE_CASH)->sum('current_balance');
        $cardTotal = (float) $storages->where('type', CashStorage::TYPE_CARD)->sum('current_balance');
        $bkashTotal = (float) $storages->where('type', CashStorage::TYPE_BKASH)->sum('current_balance');

        return response()->json([
            'cash_total' => $cashTotal,
            'card_total' => $cardTotal,
            'bkash_total' => $bkashTotal,
            'grand_total' => $cashTotal + $cardTotal + $bkashTotal,
            'storages_count' => $storages->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(CashStorage::TYPES)],
            'location_id' => ['nullable', 'integer', $this->tenantExists('locations')],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $openingBalance = (float) ($validated['opening_balance'] ?? 0);
        $validated['opening_balance'] = $openingBalance;
        $validated['current_balance'] = $openingBalance;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $storage = CashStorage::create($validated);

        return response()->json($storage->load('location'), 201);
    }

    public function show(CashStorage $cashStorage): JsonResponse
    {
        $cashStorage->load([
            'location',
            'payments' => fn ($q) => $q->latest()->limit(50)->with('order'),
        ]);

        return response()->json($cashStorage);
    }

    public function update(Request $request, CashStorage $cashStorage): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'string', Rule::in(CashStorage::TYPES)],
            'location_id' => ['nullable', 'integer', $this->tenantExists('locations')],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (isset($validated['opening_balance'])) {
            $diff = (float) $validated['opening_balance'] - (float) $cashStorage->opening_balance;
            $cashStorage->current_balance += $diff;
        }

        $cashStorage->update($validated);

        return response()->json($cashStorage->load('location'));
    }

    public function destroy(CashStorage $cashStorage): JsonResponse
    {
        $cashStorage->delete();

        return response()->json(null, 204);
    }
}
