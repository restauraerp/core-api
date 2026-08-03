<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Order extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'delivery_time' => 'datetime',
    ];

    /**
     * An order is "completed" once it is paid AND has reached the end of its
     * own workflow - which differs by type: dine-in ends at `served`, delivery
     * at `delivered`, and takeaway at `packed` (nobody marks a takeaway bag
     * "served" once the customer has walked out with it).
     *
     * This definition drives both the Completed tab and, by complement, the
     * live floor view, so it lives here rather than being spelled out at each
     * call site.
     */
    public function scopeCompleted($query)
    {
        return $query->where('payment_status', 'paid')
            ->where(function ($q) {
                $q->whereIn('status', ['served', 'delivered'])
                    ->orWhere(function ($takeaway) {
                        $takeaway->where('status', 'packed')
                            ->where('order_type', 'takeaway');
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
                        $statusQ->whereNotIn('status', ['served', 'delivered'])
                            ->whereNot(function ($packed) {
                                $packed->where('status', 'packed')
                                    ->where('order_type', 'takeaway');
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
}
