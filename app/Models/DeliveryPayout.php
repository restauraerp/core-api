<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Delivery charges paid out to a rider or courier.
 *
 * Recorded as paid rather than matched to individual orders - a rider is
 * settled for a shift or a week in one go. What matters is the running
 * balance: delivery charges collected, less what has been paid out.
 */
class DeliveryPayout extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'location_id',
        'rider_id',
        'expense_id',
        'amount',
        'paid_on',
        'reference',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            // Plain Y-m-d: a bare 'date' cast serialises as UTC midnight, which in
            // Asia/Dhaka reads back as the previous day.
            'paid_on' => 'date:Y-m-d',
        ];
    }

    public function rider()
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }
}
