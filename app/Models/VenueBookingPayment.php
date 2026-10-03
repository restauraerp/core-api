<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money received against a venue booking - the advance, or later payments. */
class VenueBookingPayment extends Model
{
    use BelongsToTenant;

    public const METHODS = ['cash', 'bkash', 'nagad', 'card', 'bank'];

    protected $fillable = ['venue_booking_id', 'amount', 'method', 'paid_at', 'note', 'received_by'];

    protected $casts = ['amount' => 'float', 'paid_at' => 'datetime'];

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
