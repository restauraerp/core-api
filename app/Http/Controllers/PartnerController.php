<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use App\Rules\PhoneNumber;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PartnerController extends Controller
{
    public function index(Request $request)
    {
        $query = Partner::query()->withCount('orders');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $query->orderBy('name');

        if ($request->has('nopaginate')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate(config('pagination.limit')));
    }

    public function store(Request $request)
    {
        $partner = Partner::create($this->validated($request));

        return response()->json($partner, 201);
    }

    public function show(Partner $partner)
    {
        return response()->json($partner->loadCount('orders'));
    }

    public function update(Request $request, Partner $partner)
    {
        $partner->update($this->validated($request, $partner));

        return response()->json($partner);
    }

    /**
     * Deleting a partner would take its orders' attribution with it, so a
     * partner that has ever sent an order is switched off rather than removed -
     * the same rule outlets follow, and for the same reason.
     */
    public function destroy(Partner $partner)
    {
        if ($partner->orders()->exists()) {
            return response()->json([
                'message' => 'This partner has sent orders, and deleting it would detach them from the money they earned. Switch it off instead to stop it appearing at the till.',
                'error' => 'partner_has_orders',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $partner->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Partner $partner = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', $partner
                ? $this->tenantUnique('partners')->ignore($partner->id)
                : $this->tenantUnique('partners')],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', PhoneNumber::any()],
            'email' => ['nullable', 'email:rfc,strict', 'max:255'],
            // Nobody works for free and nobody takes everything, but the bounds
            // are deliberately wide - this is a negotiated number, not a
            // guess we should be making for the restaurant.
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
