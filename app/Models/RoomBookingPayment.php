<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money received against a room booking - the advance, or the rest at check-out. */
class RoomBookingPayment extends Model
{
    use BelongsToTenant;

    public const METHODS = ['cash', 'bkash', 'nagad', 'card', 'bank'];

    protected $fillable = ['room_booking_id', 'amount', 'method', 'paid_at', 'note', 'received_by'];

    protected $casts = ['amount' => 'float', 'paid_at' => 'datetime'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(RoomBooking::class, 'room_booking_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
