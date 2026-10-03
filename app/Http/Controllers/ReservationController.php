<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Table;
use App\Rules\PhoneNumber;
use App\Support\PhoneNumber as Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Table bookings: the restaurant's side.
 *
 * Requests from the website arrive Pending, with the guest's phone number;
 * staff call the guest and approve or decline (TRANSITIONS on the model says
 * what may follow what). Staff can also take a booking themselves - by phone
 * or at the door - which is confirmed as it is entered. Behind module:crm and
 * the manage_reservations permission.
 */
class ReservationController extends Controller
{
    /**
     * GET /reservations?view=requests|upcoming|today|past|all&location_id=&q=&date=
     *
     * Requests and upcoming bookings soonest first, past ones latest first.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'view' => ['nullable', Rule::in(['requests', 'upcoming', 'today', 'past', 'all'])],
            'location_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $now = now();
        $today = [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
        $view = $validated['view'] ?? 'requests';

        $query = Reservation::with(['location:id,name', 'table:id,name,capacity', 'handler:id,name', 'caller:id,name'])
            ->when($validated['location_id'] ?? null, fn ($q, $id) => $q->where('location_id', $id))
            ->when($validated['date'] ?? null, fn ($q, $d) => $q->whereBetween('reservation_date', [
                Carbon::createFromFormat('Y-m-d', $d, config('app.timezone'))->startOfDay(),
                Carbon::createFromFormat('Y-m-d', $d, config('app.timezone'))->endOfDay(),
            ]))
            ->when($validated['q'] ?? null, function ($q, $search) {
                $digits = preg_replace('/\D+/', '', $search);
                $q->where(fn ($w) => $w->where('guest_name', 'like', "%{$search}%")
                    ->when(strlen($digits) >= 3, fn ($w) => $w->orWhere('guest_phone', 'like', '%'.substr($digits, -9).'%')));
            });

        match ($view) {
            'requests' => $query->where('status', Reservation::PENDING)->orderBy('reservation_date'),
            'upcoming' => $query->whereIn('status', [Reservation::CONFIRMED, Reservation::SEATED])
                ->where('reservation_date', '>=', $today[0])->orderBy('reservation_date'),
            'today' => $query->whereBetween('reservation_date', $today)->orderBy('reservation_date'),
            'past' => $query->where(fn ($q) => $q->where('reservation_date', '<', $today[0])
                ->orWhereIn('status', [Reservation::DECLINED, Reservation::CANCELLED, Reservation::COMPLETED, Reservation::NO_SHOW]))
                ->orderByDesc('reservation_date'),
            default => $query->orderByDesc('reservation_date'),
        };

        $page = $query->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Reservation $r) => $this->present($r)),
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            // For the tab badges.
            'counts' => [
                'requests' => Reservation::where('status', Reservation::PENDING)->count(),
                'today' => Reservation::whereBetween('reservation_date', $today)->whereIn('status', Reservation::OPEN)->count(),
                'upcoming' => Reservation::whereIn('status', [Reservation::CONFIRMED, Reservation::SEATED])->where('reservation_date', '>=', $today[0])->count(),
            ],
        ]);
    }

    /** POST /reservations - a booking taken by staff (by phone, at the door). Confirmed unless said otherwise. */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate($this->rules() + [
            'status' => ['nullable', Rule::in([Reservation::PENDING, Reservation::CONFIRMED])],
        ]);

        $at = $this->bookingTime($validated);
        $phone = Phone::normalise($validated['phone']) ?? $validated['phone'];
        $customer = Customer::firstOrCreate(['phone' => $phone], ['name' => $validated['name']]);
        $status = $validated['status'] ?? Reservation::CONFIRMED;

        $reservation = Reservation::create([
            'customer_id' => $customer->id,
            'location_id' => $validated['location_id'],
            'table_id' => $validated['table_id'] ?? null,
            'guest_name' => $validated['name'],
            'guest_phone' => $phone,
            'reservation_date' => $at,
            'guest_count' => $validated['guests'],
            'notes' => $validated['notes'] ?? null,
            'status' => $status,
            'source' => 'staff',
            'handled_by' => $status === Reservation::CONFIRMED ? $request->user()->getKey() : null,
            'handled_at' => $status === Reservation::CONFIRMED ? now() : null,
        ]);

        return response()->json($this->present($reservation->fresh(['location', 'table', 'handler', 'caller'])), 201);
    }

    public function show(Request $request, Reservation $reservation): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json($this->present($reservation->load(['location', 'table', 'handler', 'caller'])));
    }

    /** PUT /reservations/{id} - change the details: move it, more guests, a table, notes. */
    public function update(Request $request, Reservation $reservation): JsonResponse
    {
        $this->authorizeManage($request);

        $rules = collect($this->rules())->map(fn ($rule) => array_merge(['sometimes'], $rule))->all();
        $validated = $request->validate($rules);

        $merged = [
            'location_id' => $validated['location_id'] ?? $reservation->location_id,
            'date' => $validated['date'] ?? $reservation->reservation_date?->setTimezone(config('app.timezone'))->format('Y-m-d'),
            'time' => $validated['time'] ?? $reservation->reservation_date?->setTimezone(config('app.timezone'))->format('H:i'),
            'table_id' => array_key_exists('table_id', $validated) ? $validated['table_id'] : $reservation->table_id,
        ];
        $this->checkTable($merged['table_id'], (int) $merged['location_id']);

        $changes = array_filter([
            'location_id' => $validated['location_id'] ?? null,
            'guest_name' => $validated['name'] ?? null,
            'guest_phone' => isset($validated['phone']) ? (Phone::normalise($validated['phone']) ?? $validated['phone']) : null,
            'guest_count' => $validated['guests'] ?? null,
        ], fn ($v) => $v !== null);

        if (isset($validated['date']) || isset($validated['time'])) {
            $changes['reservation_date'] = $this->bookingTime($merged, allowPast: true);
        }
        if (array_key_exists('table_id', $validated)) {
            $changes['table_id'] = $validated['table_id'];
        }
        if (array_key_exists('notes', $validated)) {
            $changes['notes'] = $validated['notes'];
        }

        $reservation->update($changes);

        return response()->json($this->present($reservation->fresh(['location', 'table', 'handler', 'caller'])));
    }

    /**
     * POST /reservations/{id}/status - approve, decline, seat, complete,
     * no-show, cancel or reopen, as TRANSITIONS allows. Approving can give it
     * a table; declining can say why.
     */
    public function status(Request $request, Reservation $reservation): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(Reservation::TRANSITIONS))],
            'reason' => ['nullable', 'string', 'max:300'],
            'table_id' => ['nullable', 'integer'],
        ]);

        $to = $validated['status'];
        if ($to === $reservation->status) {
            return response()->json($this->present($reservation->load(['location', 'table', 'handler', 'caller'])));
        }
        if (! $reservation->canMoveTo($to)) {
            throw ValidationException::withMessages(['status' => ["A {$reservation->status} booking cannot be marked {$to}."]]);
        }

        $changes = ['status' => $to];
        if (in_array($to, [Reservation::CONFIRMED, Reservation::DECLINED], true)) {
            $changes += ['handled_by' => $request->user()->getKey(), 'handled_at' => now()];
        }
        if ($to === Reservation::DECLINED) {
            $changes['decline_reason'] = $validated['reason'] ?? null;
        }
        if ($to === Reservation::PENDING) {
            $changes += ['decline_reason' => null, 'handled_by' => null, 'handled_at' => null];
        }
        if (array_key_exists('table_id', $validated) && $validated['table_id'] !== null) {
            $this->checkTable($validated['table_id'], $reservation->location_id);
            $changes['table_id'] = $validated['table_id'];
        }

        $reservation->update($changes);

        return response()->json($this->present($reservation->fresh(['location', 'table', 'handler', 'caller'])));
    }

    /** POST /reservations/{id}/called - notes that the guest was called. */
    public function called(Request $request, Reservation $reservation): JsonResponse
    {
        $this->authorizeManage($request);

        $reservation->update(['called_at' => now(), 'called_by' => $request->user()->getKey()]);

        return response()->json($this->present($reservation->fresh(['location', 'table', 'handler', 'caller'])));
    }

    public function destroy(Request $request, Reservation $reservation): JsonResponse
    {
        $this->authorizeManage($request);

        $reservation->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            // The number staff call to confirm - always required.
            'phone' => ['required', 'string', 'max:20', PhoneNumber::mobile()],
            'location_id' => ['required', 'integer', $this->tenantExists('locations')],
            'table_id' => ['nullable', 'integer', $this->tenantExists('tables')],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'guests' => ['required', 'integer', 'min:1', 'max:200'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** The booking's time, in the restaurant's timezone. */
    private function bookingTime(array $v, bool $allowPast = false): Carbon
    {
        $this->checkTable($v['table_id'] ?? null, (int) $v['location_id']);
        $at = Carbon::createFromFormat('Y-m-d H:i', $v['date'].' '.$v['time'], config('app.timezone'));

        // A booking taken late is fine; one dated last week is a typo.
        if (! $allowPast && $at->lt(now()->subHours(12))) {
            throw ValidationException::withMessages(['date' => ['That time has already passed.']]);
        }

        return $at;
    }

    private function checkTable(?int $tableId, int $locationId): void
    {
        if ($tableId !== null && ! Table::whereKey($tableId)->where('location_id', $locationId)->exists()) {
            throw ValidationException::withMessages(['table_id' => ['That table is not at this branch.']]);
        }
    }

    private function authorizeManage(Request $request): void
    {
        if (! $request->user()->can('manage_reservations')) {
            abort(403, 'You do not have permission to manage table bookings.');
        }
    }

    /** @return array<string, mixed> */
    private function present(Reservation $r): array
    {
        $at = $r->reservation_date?->copy()->setTimezone(config('app.timezone'));

        return [
            'id' => $r->id,
            'status' => $r->status,
            'next' => Reservation::TRANSITIONS[$r->status] ?? [],
            'guest_name' => $r->guest_name,
            'guest_phone' => $r->guest_phone,
            'guests' => $r->guest_count,
            'at' => $at?->toIso8601String(),
            // In the restaurant's own time, whatever the viewer's browser says.
            'date' => $at?->format('Y-m-d'),
            'time' => $at?->format('H:i'),
            'location' => $r->location ? ['id' => $r->location->id, 'name' => $r->location->name] : null,
            'table' => $r->table ? ['id' => $r->table->id, 'name' => $r->table->name, 'capacity' => $r->table->capacity] : null,
            'notes' => $r->notes,
            'source' => $r->source ?? 'staff',
            'decline_reason' => $r->decline_reason,
            'handled_by' => $r->handler?->name,
            'handled_at' => $r->handled_at?->toIso8601String(),
            'called_at' => $r->called_at?->toIso8601String(),
            'called_by' => $r->caller?->name,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
