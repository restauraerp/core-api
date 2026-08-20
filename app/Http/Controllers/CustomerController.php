<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Organization;
use App\Rules\PhoneNumber;
use App\Support\PhoneNumber as PhoneNumberSupport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    /**
     * How the list may be ordered, and what each one means in SQL.
     *
     * `recent` is the default and is *most recent purchase*, not most recently
     * added: the useful question at a till is who was last in, and a customer
     * typed in six months ago who came back yesterday belongs at the top.
     * Customers who have never ordered have no last order to sort by and sort
     * last in every direction, which is where they belong in all three.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SORTS = [
        'recent' => ['last_order_at', 'desc'],
        'value' => ['orders_total', 'desc'],
        'name' => ['name', 'asc'],
    ];

    public function index(Request $request)
    {
        $query = $this->listQuery($request);

        if ($request->has('nopaginate')) {
            return response()->json($query->get());
        }

        $perPage = (int) $request->integer('per_page', config('pagination.limit'));
        $customers = $query->paginate(max(1, min($perPage, 100)))->withQueryString();

        // `total` on the paginator already counts only what the filters
        // matched, which is what "Showing 15 of 213" has to say on a search.
        // `total_customers` is the restaurant's whole address book, so the
        // client can also say what fraction of it is being looked at.
        return response()->json(
            $customers->toArray() + ['total_customers' => Customer::query()->count()],
        );
    }

    /**
     * One customer, with everything they have ever bought.
     *
     * The outstanding balance rides along with the page rather than being a
     * second call: what a customer owes is the first thing anyone opening their
     * record wants to know, and a number that arrives a moment later is a
     * number somebody acts before seeing.
     */
    public function orders(Request $request, Customer $customer)
    {
        $orders = $customer->orders()
            ->with(['items.product', 'payments'])
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', config('pagination.limit')));

        // Across every due order, not just this page of them. Payments come
        // with them because the balance is what is owed less what has already
        // been collected, and a tab settled in part is the ordinary case.
        $due = $customer->orders()->due()->with('payments')->get();

        return response()->json($orders->toArray() + [
            'outstanding' => [
                'orders' => $due->count(),
                'amount' => round($due->sum(fn ($order) => $order->amount_outstanding), 2),
            ],
        ]);
    }

    /**
     * The list as CSV, under whatever search, filter and sort is applied.
     *
     * Streamed rather than built in memory: an export is the one call that
     * deliberately has no page limit, and a chain with tens of thousands of
     * customers should not decide how much memory PHP needs.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->listQuery($request);
        $filename = 'customers-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // Excel reads a CSV as the system codepage unless the file says
            // otherwise, and mangles every Bengali name in it. The BOM is what
            // tells it UTF-8.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'ID', 'Name', 'Phone', 'Email', 'Address', 'Organization',
                'Tier', 'Loyalty points', 'Orders', 'Total spent', 'Last order',
            ]);

            $query->chunk(500, function ($customers) use ($handle) {
                foreach ($customers as $customer) {
                    fputcsv($handle, [
                        $customer->id,
                        $customer->name,
                        $customer->phone,
                        $customer->email,
                        $customer->address,
                        $customer->organization?->name,
                        $customer->tier,
                        $customer->loyalty_points,
                        $customer->orders_count,
                        number_format((float) $customer->orders_total, 2, '.', ''),
                        $customer->last_order_at,
                    ]);
                }
            }, 'customers.id');

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * The query behind the list, the export and the counts, so a CSV can never
     * disagree with the page it was downloaded from.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Customer>
     */
    private function listQuery(Request $request)
    {
        $query = Customer::query()
            ->with('organization')
            ->withCount('orders')
            ->withSum('orders as orders_total', 'total')
            ->withMax('orders as last_order_at', 'created_at');

        if ($search = trim((string) $request->input('search'))) {
            // Grouped. Written flat as `where(...)->orWhere(...)`, the first OR
            // escapes every condition added after it - so a filtered search
            // would return unfiltered rows, and once this query is scoped by
            // anything else that becomes a way to read past the scope.
            $query->where(function ($q) use ($search) {
                foreach (['name', 'phone', 'email', 'address', 'tier'] as $column) {
                    $q->orWhere($column, 'like', "%{$search}%");
                }

                // Typed as it is written rather than as it is stored: somebody
                // searching "01712345678" should find "+8801712345678".
                if ($normalised = PhoneNumberSupport::normalise($search)) {
                    $q->orWhere('phone', 'like', "%{$normalised}%");
                }

                $q->orWhereHas('organization', fn ($org) => $org->where('name', 'like', "%{$search}%"));
            });
        }

        // "Customers who have spent at least X with us", counted across every
        // order rather than per order - the question is what a customer is
        // worth, and someone who came back ten times for small amounts is
        // worth more than someone who came once.
        if ($request->filled('min_purchase')) {
            $query->having('orders_total', '>=', (float) $request->input('min_purchase'));
        }

        [$column, $direction] = self::SORTS[$request->input('sort')] ?? self::SORTS['recent'];

        return $query->orderBy($column, $direction)->orderBy('id', 'desc');
    }

    /**
     * The phone is canonicalised before the rules run, not after.
     *
     * Customer::setPhoneAttribute would normalise it either way, but by then
     * the uniqueness rule has already looked - and it would have looked for
     * `01712345678` while the row it should have found is stored as
     * `+8801712345678`. The duplicate is created, and the second row quietly
     * shadows the first customer's order history.
     */
    private function canonicalisePhone(Request $request): void
    {
        if ($request->filled('phone')) {
            $request->merge([
                'phone' => PhoneNumberSupport::normalise($request->input('phone')) ?? $request->input('phone'),
            ]);
        }
    }

    public function store(Request $request)
    {
        $this->canonicalisePhone($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'max:20', PhoneNumber::mobile(), $this->tenantUnique('customers')],
            'email' => 'nullable|email:rfc,strict|max:255',
            'address' => 'nullable|string',
            'loyalty_points' => 'nullable|integer',
            'tier' => 'nullable|string|max:50',
            'organization_id' => ['nullable', 'integer', $this->tenantExists('organizations')],
            'organization_name' => 'nullable|string|max:255',
            'google_map_location' => 'nullable|string',
        ]);

        if (empty($validated['organization_id']) && !empty($validated['organization_name'])) {
            $org = Organization::firstOrCreate(['name' => $validated['organization_name']]);
            $validated['organization_id'] = $org->id;
        }

        $customer = Customer::create($validated);
        $customer->load('organization');
        return response()->json($customer, 201);
    }

    public function show(Customer $customer)
    {
        $customer->load('organization');
        return response()->json($customer);
    }

    public function update(Request $request, Customer $customer)
    {
        $this->canonicalisePhone($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'max:20', PhoneNumber::mobile(), $this->tenantUnique('customers')->ignore($customer->id)],
            'email' => 'nullable|email:rfc,strict|max:255',
            'address' => 'nullable|string',
            'loyalty_points' => 'nullable|integer',
            'tier' => 'nullable|string|max:50',
            'organization_id' => ['nullable', 'integer', $this->tenantExists('organizations')],
            'organization_name' => 'nullable|string|max:255',
            'google_map_location' => 'nullable|string',
        ]);

        if (empty($validated['organization_id']) && !empty($validated['organization_name'])) {
            $org = Organization::firstOrCreate(['name' => $validated['organization_name']]);
            $validated['organization_id'] = $org->id;
        }

        $customer->update($validated);
        $customer->load('organization');
        return response()->json($customer);
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();
        return response()->json(null, 204);
    }
}