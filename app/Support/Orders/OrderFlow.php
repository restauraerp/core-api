<?php

namespace App\Support\Orders;

use App\Models\Product;
use Illuminate\Support\Carbon;

/**
 * How an order moves from placed to finished.
 *
 * This is the whole rule book, in one class, because it used to live in three
 * places at once - the till, the storefront checkout and the orders screen each
 * decided what an order's status should be, and the API accepted whatever word
 * arrived. A restaurant running the till and the kitchen display at the same
 * time cannot afford them to disagree about what "ready" means.
 *
 * Two things decide how an order runs:
 *
 *   - **When it is due.** Takeaway, delivery and catering orders carry a
 *     delivery time; an empty one means ASAP. Nothing is prepared for an order
 *     that is not due yet, so a scheduled order waits at `pending` until a chef
 *     starts it. Cooking a catering order the moment it is booked is how food
 *     for Saturday gets made on Tuesday.
 *
 *   - **What is in it.** Only products marked "needs to cook" involve the
 *     kitchen. An order of a bottle of water and a packet of crisps has nothing
 *     to prepare, so the cooking stage is not part of its run at all - it opens
 *     at `ready_to_serve` and never reaches the kitchen display, even when it
 *     was scheduled for later.
 *
 * The payment track (`payment_status`) is separate and untouched by any of
 * this: an order can be paid at any stage, and being paid finishes nothing.
 */
class OrderFlow
{
    public const PENDING = 'pending';

    public const COOKING = 'cooking';

    public const READY_TO_SERVE = 'ready_to_serve';

    public const SERVED = 'served';

    public const PACKED = 'packed';

    public const PICKED_UP = 'picked_up';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    public const DINE_IN = 'dine_in';

    public const TAKEAWAY = 'takeaway';

    public const DELIVERY = 'delivery';

    public const CATERING = 'catering';

    /**
     * What staff call each stage. The UI reads these rather than inventing its
     * own wording, so the screen and the kitchen say the same thing.
     */
    public const LABELS = [
        self::PENDING => 'Pending',
        self::COOKING => 'Cooking',
        self::READY_TO_SERVE => 'Ready to Serve',
        self::SERVED => 'Served',
        self::PACKED => 'Packed',
        self::PICKED_UP => 'Picked Up By Delivery',
        self::DELIVERED => 'Delivered',
        self::CANCELLED => 'Cancelled',
    ];

    /**
     * The stages after the food is ready, per order type. Catering is delivery
     * with a bigger van.
     *
     * @var array<string, list<string>>
     */
    private const TAILS = [
        self::DINE_IN => [self::SERVED],
        self::TAKEAWAY => [self::PACKED],
        self::DELIVERY => [self::PACKED, self::PICKED_UP, self::DELIVERED],
        self::CATERING => [self::PACKED, self::PICKED_UP, self::DELIVERED],
    ];

    /**
     * Statuses that used to mean the same thing, so an old client or a row
     * written before the rename still resolves.
     */
    private const RENAMED = [
        'cooked' => self::READY_TO_SERVE,
        'picked' => self::PICKED_UP,
        'canceled' => self::CANCELLED,
        'void' => self::CANCELLED,
    ];

    /**
     * The run an order follows, start to finish.
     *
     * `pending` is only in it for an order that was scheduled: an ASAP order
     * has nothing to wait for. `cooking` is only in it when something on the
     * order needs preparing.
     *
     * @return list<string>
     */
    public function stages(string $orderType, bool $needsCooking = true, bool $scheduled = true): array
    {
        return [
            ...($scheduled ? [self::PENDING] : []),
            ...($needsCooking ? [self::COOKING] : []),
            self::READY_TO_SERVE,
            ...(self::TAILS[$orderType] ?? self::TAILS[self::DINE_IN]),
        ];
    }

    /** Where a new order starts. */
    public function openingStatus(?Carbon $deliveryTime, bool $needsCooking): string
    {
        if ($this->isScheduled($deliveryTime)) {
            return self::PENDING;
        }

        return $needsCooking ? self::COOKING : self::READY_TO_SERVE;
    }

    /** An order due later than now is one nobody should be preparing yet. */
    public function isScheduled(?Carbon $deliveryTime): bool
    {
        return $deliveryTime !== null && $deliveryTime->isFuture();
    }

    /**
     * Minutes until the food is wanted. Negative once that moment has passed,
     * and null for an order with no time on it - which is not "no rush", it is
     * ASAP, so callers treat null as due now.
     */
    public function minutesUntilDue(?Carbon $deliveryTime): ?int
    {
        return $deliveryTime === null ? null : (int) round(now()->diffInSeconds($deliveryTime, false) / 60);
    }

    /**
     * Whether the kitchen should be starting this now: it is due inside the
     * lead window, or already overdue.
     *
     * An order with no delivery time is due immediately - a till order placed
     * without a time is wanted as soon as it can be made.
     */
    public function isDueWithin(?Carbon $deliveryTime, int $leadMinutes): bool
    {
        $minutes = $this->minutesUntilDue($deliveryTime);

        return $minutes === null || $minutes <= $leadMinutes;
    }

    /**
     * The stages an order at this status may move to next.
     *
     * One step forward only - skipping a stage would mean an order marked
     * delivered that nobody ever packed. Cancelling is always available and is
     * not part of the run, so it is not listed here.
     *
     * @return list<string>
     */
    public function next(string $orderType, ?string $current, bool $needsCooking = true): array
    {
        $current = $this->normalise($current);

        if ($current === self::CANCELLED) {
            // Reopening puts it back at the front of its run.
            return [$this->openingStatus(null, $needsCooking)];
        }

        $stages = $this->stages($orderType, $needsCooking);
        $position = array_search($current, $stages, true);

        if ($position === false) {
            // A status from outside the run: offer the first real stage rather
            // than stranding the order with no button at all.
            return [$needsCooking ? self::COOKING : self::READY_TO_SERVE];
        }

        $next = $stages[$position + 1] ?? null;

        return $next === null ? [] : [$next];
    }

    /**
     * A move an order is allowed to make. Cancelling is allowed from anywhere -
     * a customer can change their mind at any point.
     */
    public function canTransition(string $orderType, ?string $from, string $to, bool $needsCooking = true): bool
    {
        $to = $this->normalise($to);

        if ($to === self::CANCELLED) {
            return true;
        }

        return in_array($to, $this->next($orderType, $from, $needsCooking), true);
    }

    /** Whether the order has reached the end of its own run. */
    public function isTerminal(string $orderType, ?string $status): bool
    {
        $stages = $this->stages($orderType);

        return $this->normalise($status) === end($stages);
    }

    /**
     * Every status that finishes an order, across all types - for the queries
     * that ask "what is still on the floor?".
     *
     * @return list<string>
     */
    public function terminalStatuses(): array
    {
        $terminal = [];

        foreach (array_keys(self::TAILS) as $type) {
            $stages = $this->stages($type);
            $terminal[] = end($stages);
        }

        return array_values(array_unique($terminal));
    }

    public function label(?string $status): string
    {
        return self::LABELS[$this->normalise($status)] ?? ucfirst((string) $status);
    }

    /** Old spellings resolve to what they became. */
    public function normalise(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $status = strtolower(trim($status));

        return self::RENAMED[$status] ?? $status;
    }

    /**
     * Whether any of these products involve the kitchen.
     *
     * @param  list<int>  $productIds
     */
    public function productsNeedCooking(array $productIds): bool
    {
        if ($productIds === []) {
            return false;
        }

        return Product::whereIn('id', $productIds)->where('needs_cooking', true)->exists();
    }
}
