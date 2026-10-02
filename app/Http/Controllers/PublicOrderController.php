<?php

namespace App\Http\Controllers;

use App\Jobs\Site\SendGa4Conversion;
use App\Jobs\Site\SendMetaConversion;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SiteIntegration;
use App\Models\Tenant;
use App\Rules\PhoneNumber;
use App\Support\Billing\Modules;
use App\Support\Billing\Subscription;
use App\Support\Branding\RestaurantBranding;
use App\Support\Orders\OrderFlow;
use App\Support\PhoneNumber as Phone;
use App\Support\Site\Conversion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The restaurant's online order link: /order/{restaurant-code}.
 *
 * Customers browse the menu and place their own order, with no account. Every
 * restaurant has one, addressed by its restaurant code, so the tenant comes
 * from the URL rather than a header or a token.
 *
 * Nothing the browser sends is trusted for money. Prices come from the product
 * table, tax from the restaurant's own rules, and there is no way to post a
 * discount, a delivery charge or a payment - an online order is always placed
 * unpaid, to be settled on pickup or delivery. Only what a customer is entitled
 * to choose is accepted: which branch, takeaway or delivery, what and how many,
 * and who they are.
 */
class PublicOrderController extends Controller
{
    /** Order types a customer can choose online. Dine-in needs a table; catering needs a conversation. */
    private const ORDER_TYPES = [OrderFlow::TAKEAWAY, OrderFlow::DELIVERY];

    /** How many past orders one browser can look up at once. */
    private const MAX_TRACKED = 20;

    public function __construct(private TenantContext $context) {}

    /** GET /order/{slug} - the restaurant, its branches and its menu. */
    public function menu(string $slug): JsonResponse
    {
        $tenant = $this->tenantFor($slug);

        return $this->context->runFor($tenant, function () use ($tenant) {
            $branding = RestaurantBranding::current();

            $locations = Location::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'address', 'phone', 'map_url'])
                // A map link for the website's Locations page - web addresses
                // only, so a saved value can never become script there.
                ->map(fn (Location $l) => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'address' => $l->address,
                    'phone' => $l->phone,
                    'map_url' => is_string($l->map_url) && preg_match('#^https?://#i', $l->map_url) ? $l->map_url : null,
                ])
                ->values();

            $products = Product::with(['images', 'locations'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            $categoryIds = $products->pluck('category_id')->filter()->unique();

            return response()->json([
                'restaurant' => [
                    'code' => $tenant->slug,
                    'name' => $branding['name'] ?? $tenant->name,
                    'address' => $branding['address'] ?? null,
                    'phone' => $branding['phone'] ?? null,
                    'logo_url' => $branding['logo_url'] ?? null,
                    'currency' => $branding['currency'] ?? '৳',
                ],
                'accepting_orders' => $this->acceptingOrders($tenant),
                // The restaurant's website tags, so ordering reports to them too.
                'tracking' => SiteIntegration::publicTags(),
                'order_types' => $this->orderTypes($tenant),
                'locations' => $locations,
                'categories' => ProductCategory::whereIn('id', $categoryIds)
                    ->where('is_active', true)
                    ->ordered()
                    ->get(['id', 'name']),
                'products' => $products->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'price' => (float) $product->price,
                    'category_id' => $product->category_id,
                    'image_url' => $product->images->first()?->url,
                    // Null means every branch; otherwise the branches that have it.
                    'location_ids' => $this->availableAt($product),
                ])->values(),
            ]);
        });
    }

    /** POST /order/{slug} - place an order. */
    public function store(Request $request, string $slug): JsonResponse
    {
        $tenant = $this->tenantFor($slug);

        return $this->context->runFor($tenant, function () use ($request, $tenant) {
            if (! $this->acceptingOrders($tenant)) {
                return response()->json([
                    'message' => 'This restaurant is not taking online orders right now. Please call them to order.',
                    'error' => 'not_accepting_orders',
                ], 423);
            }

            $validated = $request->validate([
                'location_id' => ['nullable', 'integer'],
                'order_type' => ['required', Rule::in($this->orderTypes($tenant))],
                'name' => ['required', 'string', 'max:100'],
                'phone' => ['required', 'string', 'max:20', PhoneNumber::mobile()],
                'delivery_address' => ['nullable', 'required_if:order_type,'.OrderFlow::DELIVERY, 'string', 'max:500'],
                'note' => ['nullable', 'string', 'max:500'],
                'items' => ['required', 'array', 'min:1', 'max:50'],
                'items.*.product_id' => ['required', 'integer'],
                'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
                'items.*.notes' => ['nullable', 'string', 'max:200'],
            ] + Conversion::REQUEST_RULES, [
                'delivery_address.required_if' => 'Enter the address to deliver to.',
                'items.required' => 'Add at least one item to your order.',
            ]);

            $location = $this->locationFor($validated['location_id'] ?? null);
            $lines = $this->priceLines($validated['items'], $location);

            $customer = $this->customerFor($validated['name'], $validated['phone'], $validated['delivery_address'] ?? null);

            // Through the same pipeline as a till order - tax from the
            // restaurant's rules, the day's token, stock taken off the shelf,
            // and the kitchen status the items call for - but with only the
            // fields a customer may set. Nothing here carries a price the
            // customer chose, a discount, or a payment.
            $internal = Request::create('/', 'POST', [
                'location_id' => $location->id,
                'order_type' => $validated['order_type'],
                'subtotal' => 0,
                'discount_amount' => 0,
                'customer_id' => $customer->id,
                'delivery_address' => $validated['order_type'] === OrderFlow::DELIVERY ? $validated['delivery_address'] : null,
                'items' => $lines,
            ]);
            $internal->setUserResolver(fn () => null);

            $order = app(OrderController::class)->store($internal)->getData(true);

            $model = Order::findOrFail($order['id']);
            $model->forceFill([
                'source' => 'online',
                'customer_note' => $validated['note'] ?? null,
            ])->save();

            self::reportConversion(Conversion::forOrder($model, $request, $validated['name'], $validated['phone']));

            return response()->json([
                'order_id' => $model->id,
                'tracking_key' => $this->trackingKey($model),
                'token_number' => $model->token_number,
                'order_type' => $model->order_type,
                'location' => $location->only(['id', 'name', 'address', 'phone']),
                'subtotal' => (float) $model->subtotal,
                'tax_amount' => (float) $model->tax_amount,
                'total' => (float) $model->total,
                'currency' => RestaurantBranding::current()['currency'] ?? '৳',
            ], 201);
        });
    }

    /**
     * Queues a website conversion for each ad platform the restaurant has set
     * up server-side. Runs in the restaurant's context, after the order or
     * booking is saved.
     *
     * @param  array<string, mixed>  $conversion
     */
    public static function reportConversion(array $conversion): void
    {
        $integration = SiteIntegration::current();
        $tenantId = (int) app(TenantContext::class)->id();

        $jobs = array_filter([
            $integration?->sendsMeta() ? new SendMetaConversion($tenantId, $conversion) : null,
            $integration?->sendsGa4() ? new SendGa4Conversion($tenantId, $conversion) : null,
        ]);

        foreach ($jobs as $job) {
            // Reporting must never cost the guest their order - not even on a
            // sync queue, where a platform's error would surface right here.
            try {
                dispatch($job);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * POST /order/{slug}/my-orders - the customer's own orders, by key.
     *
     * The browser keeps each order it placed as an id and the tracking key it
     * was given (a cookie on the ordering page), and sends them back here. No
     * key, no order: the id alone is a sequential number anyone could walk.
     * POST rather than GET so the keys stay out of URLs and access logs.
     */
    public function mine(Request $request, string $slug): JsonResponse
    {
        $tenant = $this->tenantFor($slug);

        $validated = $request->validate([
            'orders' => ['present', 'array', 'max:'.self::MAX_TRACKED],
            'orders.*.id' => ['required', 'integer'],
            'orders.*.key' => ['required', 'string', 'max:64'],
        ]);

        return $this->context->runFor($tenant, function () use ($validated) {
            $keys = collect($validated['orders'])->pluck('key', 'id');

            $orders = Order::withTrashed()
                ->with(['items.product:id,name', 'location:id,name,phone'])
                ->whereIn('id', $keys->keys())
                ->where('source', 'online')
                ->latest('id')
                ->get()
                ->filter(fn (Order $order) => hash_equals($this->trackingKey($order), (string) $keys->get($order->id)));

            return response()->json([
                'currency' => RestaurantBranding::current()['currency'] ?? '৳',
                'orders' => $orders->map(fn (Order $order) => $this->forCustomer($order))->values(),
            ]);
        });
    }

    /**
     * Proof that a browser placed this order: a keyed hash of the tenant and
     * order id, so there is nothing to store and nothing to leak but the key
     * itself. Rotating APP_KEY retires every outstanding link, which is fine
     * for something a customer only needs until their food arrives.
     */
    private function trackingKey(Order $order): string
    {
        return substr(hash_hmac('sha256', 'online-order:'.$order->tenant_id.':'.$order->id, (string) config('app.key')), 0, 40);
    }

    /** What the customer sees of an order: progress and the bill, nothing internal. */
    private function forCustomer(Order $order): array
    {
        $flow = app(OrderFlow::class);
        $status = $order->trashed() ? OrderFlow::CANCELLED : $flow->normalise($order->status);

        return [
            'id' => $order->id,
            'token_number' => $order->token_number,
            'order_type' => $order->order_type,
            'status' => $status,
            'status_label' => $this->customerStatus($status, $order->order_type),
            'finished' => $status === OrderFlow::CANCELLED || $flow->isTerminal((string) $order->order_type, $status),
            'paid' => $order->payment_status === 'paid',
            'placed_at' => $order->created_at?->toIso8601String(),
            'location' => $order->location?->only(['name', 'phone']),
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product?->name ?? 'Item',
                'qty' => (int) $item->quantity,
                'total' => (float) $item->price * (int) $item->quantity,
            ])->values(),
            'subtotal' => (float) $order->subtotal,
            'tax_amount' => (float) $order->tax_amount,
            'delivery_charge' => (float) $order->delivery_charge,
            'total' => (float) $order->total,
        ];
    }

    /** Status in the customer's words rather than the kitchen's. */
    private function customerStatus(?string $status, ?string $orderType): string
    {
        $delivery = $orderType !== OrderFlow::TAKEAWAY;

        return match ($status) {
            OrderFlow::PENDING => 'Received',
            OrderFlow::COOKING => 'Being prepared',
            OrderFlow::READY_TO_SERVE => $delivery ? 'Ready' : 'Ready for pickup',
            OrderFlow::PACKED => $delivery ? 'Packed, waiting for the rider' : 'Ready for pickup',
            OrderFlow::PICKED_UP => 'On the way',
            OrderFlow::DELIVERED => 'Delivered',
            OrderFlow::SERVED => 'Served',
            OrderFlow::CANCELLED => 'Cancelled',
            default => 'Received',
        };
    }

    /**
     * What this restaurant can take online. Delivery needs the Delivery
     * module - without it there are no riders to send the order out with.
     *
     * @return list<string>
     */
    private function orderTypes(Tenant $tenant): array
    {
        return array_values(array_filter(
            self::ORDER_TYPES,
            fn (string $type) => $type !== OrderFlow::DELIVERY || $tenant->hasModule(Modules::DELIVERY),
        ));
    }

    private function tenantFor(string $slug): Tenant
    {
        return Tenant::where('slug', $slug)->first()
            ?? abort(404, 'We could not find that restaurant. Check the link and try again.');
    }

    /**
     * Only a restaurant that could ring the order up itself may take one.
     *
     * An allow-list, not "not read-only": a cancelled account is BLOCKED, not
     * READ_ONLY, and must not keep collecting orders nobody will cook.
     */
    private function acceptingOrders(Tenant $tenant): bool
    {
        return in_array($tenant->subscription()['state'], [Subscription::FULL, Subscription::GRACE], true);
    }

    /** The chosen branch, or the only/first active one when none was chosen. */
    private function locationFor(?int $locationId): Location
    {
        $query = Location::where('is_active', true);

        $location = $locationId !== null
            ? $query->whereKey($locationId)->first()
            : $query->orderBy('name')->first();

        if ($location === null) {
            throw ValidationException::withMessages([
                'location_id' => ['Choose a branch to order from.'],
            ]);
        }

        return $location;
    }

    /**
     * The order lines, priced from the product table.
     *
     * Every product must be on sale and available at the chosen branch - the
     * same rule the till applies. A product that has gone off the menu since
     * the customer loaded the page is refused by name, not silently dropped.
     *
     * @param  list<array{product_id: int, qty: int, notes?: ?string}>  $items
     * @return list<array{product_id: int, qty: int, price: float, notes: ?string}>
     */
    private function priceLines(array $items, Location $location): array
    {
        /** @var Collection<int, Product> $products */
        $products = Product::with('locations')
            ->whereIn('id', array_column($items, 'product_id'))
            ->get()
            ->keyBy('id');

        $lines = [];
        $unavailable = [];

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);

            if ($product === null || ! $product->is_active || ! $this->isAvailableAt($product, $location->id)) {
                $unavailable[] = $product?->name ?? 'An item';

                continue;
            }

            $lines[] = [
                'product_id' => $product->id,
                'qty' => (int) $item['qty'],
                'price' => (float) $product->price,
                'notes' => $item['notes'] ?? null,
            ];
        }

        if ($unavailable !== []) {
            throw ValidationException::withMessages([
                'items' => [implode(', ', array_unique($unavailable)).' '.(count($unavailable) === 1 ? 'is' : 'are').' not available at this branch right now. Remove '.(count($unavailable) === 1 ? 'it' : 'them').' to continue.'],
            ]);
        }

        return $lines;
    }

    /** @return list<int>|null  null when the product is on at every branch. */
    private function availableAt(Product $product): ?array
    {
        if ($product->locations->isEmpty()) {
            return null;
        }

        return $product->locations
            ->filter(fn ($location) => (bool) $location->pivot->is_available)
            ->pluck('id')
            ->values()
            ->all();
    }

    private function isAvailableAt(Product $product, int $locationId): bool
    {
        $ids = $this->availableAt($product);

        return $ids === null || in_array($locationId, $ids, true);
    }

    /**
     * The customer behind a phone number, created on their first order.
     *
     * Matched on phone, which is what the restaurant already files regulars
     * under. An existing record's name is left alone - the restaurant may have
     * corrected it - but an empty address is filled from the delivery.
     */
    private function customerFor(string $name, string $phone, ?string $address): Customer
    {
        // Looked up by the stored form: Customer normalises phones on write
        // (01711... is kept as +8801711...), so the raw value never matches and
        // a returning customer would hit the unique index instead.
        $customer = Customer::firstOrCreate(['phone' => Phone::normalise($phone) ?? $phone], ['name' => $name, 'address' => $address]);

        if ($address && empty($customer->address)) {
            $customer->update(['address' => $address]);
        }

        return $customer;
    }
}
