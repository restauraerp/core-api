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
        return $query->where('payment_status', 'paid')
            ->where(function ($q) {
                $q->whereIn('status', [OrderFlow::SERVED, OrderFlow::DELIVERED])
                    ->orWhere(function ($takeaway) {
                        $takeaway->where('status', OrderFlow::PACKED)
                            ->where('order_type', OrderFlow::TAKEAWAY);
                    });
            });
    }

    /**
     * Everything still needing attention on the floor - the complement of
     * scopeCompleted().
     *
     * Deliberately spelled out rather than written as whereNot(completed): an
     * unpaid order has payment_status NULL, and in SQL's three-valued logic
     * NOT(NULL AND true) is NULL, not true - so a plain negation would silently
     * drop exactly the orders staff most need to see.
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($unpaid) {
                $unpaid->where('payment_status', '!=', 'paid')
                    ->orWhereNull('payment_status');
            })->orWhere(function ($paidButUnfinished) {
                $paidButUnfinished->where('payment_status', 'paid')
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

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function trashedByUser()
    {
        return $this->belongsTo(User::class, 'trashed_by');
    }
}
