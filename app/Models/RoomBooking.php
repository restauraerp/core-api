<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One stay in one room type, check-in to check-out (the check-out day is
 * free for the next guest).
 *
 * Pending is a request - from the website, or held by staff - and holds no
 * room. Confirmed and Checked in hold one of the type's rooms for every
 * night of the stay; a room is assigned by check-in at the latest.
 */
class RoomBooking extends Model
{
    use BelongsToTenant;

    public const PENDING = 'Pending';

    public const CONFIRMED = 'Confirmed';

    public const CHECKED_IN = 'Checked in';

    public const CHECKED_OUT = 'Checked out';

    public const CANCELLED = 'Cancelled';

    public const DECLINED = 'Declined';

    public const NO_SHOW = 'No-show';

    /** Statuses that hold a room. */
    public const HOLDING = [self::CONFIRMED, self::CHECKED_IN];

    public const TRANSITIONS = [
        self::PENDING => [self::CONFIRMED, self::DECLINED, self::CANCELLED],
        self::CONFIRMED => [self::CHECKED_IN, self::NO_SHOW, self::CANCELLED, self::PENDING],
        self::CHECKED_IN => [self::CHECKED_OUT, self::CONFIRMED],
        self::CHECKED_OUT => [self::CHECKED_IN],
        self::DECLINED => [self::PENDING],
        self::CANCELLED => [self::PENDING],
        self::NO_SHOW => [self::CONFIRMED],
    ];

    protected $fillable = [
        'location_id', 'room_type_id', 'room_id', 'customer_id', 'guest_name', 'guest_phone', 'guest_email',
        'check_in', 'check_out', 'adults', 'children', 'nightly_rates', 'room_amount', 'extras', 'discount', 'total',
        'status', 'source', 'notes', 'decline_reason', 'handled_by', 'handled_at', 'checked_in_at', 'checked_out_at', 'last_emailed_at',
    ];

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'adults' => 'integer',
        'children' => 'integer',
        'nightly_rates' => 'array',
        'room_amount' => 'float',
        'extras' => 'array',
        'discount' => 'float',
        'total' => 'float',
        'handled_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'last_emailed_at' => 'datetime',
    ];

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function nights(): int
    {
        return (int) $this->check_in->diffInDays($this->check_out);
    }

    /** Stays that share at least one night with [from, to). */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->where('check_in', '<', $to)->where('check_out', '>', $from);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(RoomBookingPayment::class)->orderBy('paid_at');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
