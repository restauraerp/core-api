<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A table booking - asked for on the website, or taken by staff.
 *
 * A website request arrives Pending, for the restaurant to call the guest and
 * approve or decline. After that it is Seated when they come, Completed when
 * they leave, or a No-show. A declined or cancelled booking can be reopened,
 * in case it was a mistake.
 *
 * guest_name and guest_phone are the booking's own: what the guest gave, the
 * number to call.
 */
class Reservation extends Model
{
    use BelongsToTenant;

    public const PENDING = 'Pending';

    public const CONFIRMED = 'Confirmed';

    public const DECLINED = 'Declined';

    public const CANCELLED = 'Cancelled';

    public const SEATED = 'Seated';

    public const COMPLETED = 'Completed';

    public const NO_SHOW = 'No-show';

    /** Where a booking may go from each status. */
    public const TRANSITIONS = [
        self::PENDING => [self::CONFIRMED, self::DECLINED, self::CANCELLED],
        self::CONFIRMED => [self::SEATED, self::NO_SHOW, self::CANCELLED, self::PENDING],
        self::SEATED => [self::COMPLETED, self::CONFIRMED],
        self::DECLINED => [self::PENDING],
        self::CANCELLED => [self::PENDING],
        self::NO_SHOW => [self::CONFIRMED],
        self::COMPLETED => [self::SEATED],
    ];

    /** Still to come: the table is (or may be) held. */
    public const OPEN = [self::PENDING, self::CONFIRMED, self::SEATED];

    /**
     * tenant_id is deliberately absent - BelongsToTenant stamps it, and
     * listing it here would let a request body move this row to another
     * restaurant.
     */
    protected $fillable = [
        'customer_id',
        'location_id',
        'hall_id',
        'table_id',
        'guest_name',
        'guest_phone',
        'guest_email',
        'reservation_date',
        'guest_count',
        'status',
        'source',
        'notes',
        'decline_reason',
        'handled_by',
        'handled_at',
        'called_at',
        'called_by',
        'last_emailed_at',
    ];

    protected function casts(): array
    {
        return [
            'reservation_date' => 'datetime',
            'handled_at' => 'datetime',
            'called_at' => 'datetime',
            'last_emailed_at' => 'datetime',
            'guest_count' => 'integer',
        ];
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
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
