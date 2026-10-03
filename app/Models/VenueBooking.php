<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One event in one hall.
 *
 * Pending is tentative - an enquiry, a hold - and does not block the hall;
 * Confirmed does, from the start until the hall's cleanup time after the end.
 * Prices are copied onto the booking (VenuePricing), so the agreed amount
 * never moves when the hall's rent or a package changes later.
 */
class VenueBooking extends Model
{
    use BelongsToTenant;

    public const PENDING = 'Pending';

    public const CONFIRMED = 'Confirmed';

    public const COMPLETED = 'Completed';

    public const CANCELLED = 'Cancelled';

    public const DECLINED = 'Declined';

    public const TRANSITIONS = [
        self::PENDING => [self::CONFIRMED, self::DECLINED, self::CANCELLED],
        self::CONFIRMED => [self::COMPLETED, self::CANCELLED, self::PENDING],
        self::COMPLETED => [self::CONFIRMED],
        self::DECLINED => [self::PENDING],
        self::CANCELLED => [self::PENDING],
    ];

    public const EVENT_TYPES = ['birthday', 'wedding', 'holud', 'aqiqah', 'engagement', 'anniversary', 'corporate', 'meeting', 'reunion', 'other'];

    protected $fillable = [
        'location_id', 'hall_id', 'customer_id', 'guest_name', 'guest_phone', 'guest_email', 'last_emailed_at',
        'event_type', 'event_title', 'starts_at', 'ends_at', 'guests',
        'package_id', 'package_name', 'package_price', 'rent_amount', 'extras', 'discount', 'total',
        'status', 'source', 'notes', 'decline_reason', 'handled_by', 'handled_at', 'called_at', 'called_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'guests' => 'integer',
        'package_price' => 'float',
        'rent_amount' => 'float',
        'extras' => 'array',
        'discount' => 'float',
        'total' => 'float',
        'handled_at' => 'datetime',
        'called_at' => 'datetime',
        'last_emailed_at' => 'datetime',
    ];

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Bookings in a hall that overlap a time, its cleanup gap included on
     * both sides (theirs after them, ours after us).
     */
    public function scopeClashingWith(Builder $query, Hall $hall, Carbon $start, Carbon $end, ?int $except = null): Builder
    {
        $gap = $hall->buffer_minutes ?? 0;

        return $query->where('hall_id', $hall->id)
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->where('starts_at', '<', $end->copy()->addMinutes($gap))
            ->where('ends_at', '>', $start->copy()->subMinutes($gap));
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(VenuePackage::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VenueBookingPayment::class)->orderBy('paid_at');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'called_by');
    }
}
