<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Orders\OrderFlow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = [];

    /** Settled in full. */
    public const PAYMENT_PAID = 'paid';

    /** Expected to be settled before the customer leaves. */
    public const PAYMENT_UNPAID = 'unpaid';

    /** The restaurant has agreed to collect later. See scopeDue(). */
    public const PAYMENT_DUE = 'due';

    protected $casts = [
        'delivery_time' => 'datetime',
        'needs_cooking' => 'boolean',
    ];

    /**
     * The stage this order may move to next, so a till or a kitchen display
     * renders buttons from the rule book rather than from its own copy of it.
     */
    protected $appends = ['next_statuses', 'status_label'];

    /**
     * @return list<string>
     */
    public function nextStatuses(): array
    {
        return app(OrderFlow::class)->next(
            (string) $this->order_type,
            $this->status,
            (bool) $this->needs_cooking,
        );
    }

    /** @return list<string> */
    public function getNextStatusesAttribute(): array
    {
        return $this->nextStatuses();
    }

    public function getStatusLabelAttribute(): string
    {
        return app(OrderFlow::class)->label($this->status);
    }

    /**
     * An order is "completed" once it is paid AND has reached the end of its
     * own workflow - which differs by type: dine-in ends at `served`, delivery
     * at `delivered`, and takeaway at `packed` (nobody marks a takeaway bag
     * "served" once the customer has walked out with it). OrderFlow owns which
     * stage that is for each type.
     *
     * This definition drives both the Completed tab and, by complement, the
     * live floor view, so it lives here rather than being spelled out at each
     * call site.
     */
    public function scopeCompleted($query)
    {
        return $query->where('payment_status', self::PAYMENT_PAID)
            ->where(function ($q) {
                $q->whereIn('status', [OrderFlow::SERVED, OrderFlow::DELIVERED])
                    ->orWhere(function ($takeaway) {
                        $takeaway->where('status', OrderFlow::PACKED)
                            ->where('order_type', OrderFlow::TAKEAWAY);
                    });
            });
    }

    /**
     * Money the restaurant has agreed to collect later.
     *
     * A third payment state, not a flavour of unpaid. An unpaid order is one
     * the customer is expected to settle before they leave; a due order is one
     * the restaurant has decided they will not, which is a different thing to
     * chase and a different list to chase it from.
     */
    public function scopeDue($query)
    {
        return $query->where('payment_status', self::PAYMENT_DUE);
    }

    /**
     * Everything still needing attention on the floor.
     *
     * Deliberately spelled out rather than written as whereNot(completed): an
     * unpaid order has payment_status NULL, and in SQL's three-valued logic
     * NOT(NULL AND true) is NULL, not true - so a plain negation would silently
     * drop exactly the orders staff most need to see.
     *
     * `due` sits beside `paid` in the first branch, and that placement is the
     * whole reason due orders work. Without it a due order - finished in the
     * kitchen, served, and deliberately not paid for - matches "not paid" and
     * never leaves the live floor view. The tab fills with settled-by-agreement
     * orders that nobody can clear, and staff stop trusting the one screen that
     * is supposed to tell them what still needs doing.
     *
     * What each state means here:
     *   unpaid + unfinished -> active, the kitchen is working
     *   unpaid + finished   -> active, the waiter still has to collect
     *   paid   + unfinished -> active, the kitchen is working
     *   paid   + finished   -> done, it is on the Completed tab
     *   due    + unfinished -> active, the kitchen is still working
     *   due    + finished   -> off the floor, it is on the Due tab
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($awaitingPayment) {
                $awaitingPayment->whereNotIn('payment_status', [self::PAYMENT_PAID, self::PAYMENT_DUE])
                    ->orWhereNull('payment_status');
            })->orWhere(function ($settledButUnfinished) {
                $settledButUnfinished->whereIn('payment_status', [self::PAYMENT_PAID, self::PAYMENT_DUE])
                    ->where(function ($statusQ) {
                        $statusQ->whereNotIn('status', [OrderFlow::SERVED, OrderFlow::DELIVERED])
                            ->whereNot(function ($packed) {
                                $packed->where('status', OrderFlow::PACKED)
                                    ->where('order_type', OrderFlow::TAKEAWAY);
                            });
                    });
            });
        });
    }

    /**
     * What has actually been collected against this order.
     *
     * Summed from the payments rather than stored, so a part payment recorded
     * at the counter and the balance shown on screen can never disagree.
     */
    public function getAmountPaidAttribute(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function getAmountOutstandingAttribute(): float
    {
        return round(max(0, (float) $this->total - $this->amount_paid), 2);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function table()
    {
        return $this->belongsTo(Table::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    /** The third party that sent this order in, if it came through one. */
    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * What the restaurant actually keeps on this sale.
     *
     * The bill says one thing and the restaurant earns another whenever an
     * order arrives through a partner - which is exactly the gap that makes
     * aggregator revenue look healthier than it is.
     */
    public function getPartnerNetAmountAttribute(): float
    {
        if ($this->partner_id === null) {
            return (float) $this->total;
        }

        return round((float) $this->total - (float) $this->partner_commission_amount, 2);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The employee credited with the sale.
     *
     * Distinct from the `user_id` column, which records whichever account
     * created the order - very often a shared till login, and useless as a
     * measure of who actually served anybody.
     */
    public function servedBy()
    {
        return $this->belongsTo(User::class, 'served_by_user_id');
    }

    public function trashedByUser()
    {
        return $this->belongsTo(User::class, 'trashed_by');
    }
}
