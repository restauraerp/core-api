<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseOrder extends Model
{
    use BelongsToTenant;

    /**
     * A purchase order that has not been cancelled counts as delivered stock -
     * see PurchaseOrderStock.
     */
    public const CANCELLED = 'cancelled';

    public const STATUSES = ['pending', 'approved', 'received', 'cancelled'];

    /**
     * tenant_id is deliberately absent - BelongsToTenant stamps it, and
     * listing it here would let a request body move this row to another
     * restaurant.
     *
     * total_amount is absent too: it is the sum of the line rows, computed by
     * the controller, never taken from the request.
     */
    protected $fillable = [
        'supplier_id',
        'location_id',
        'created_by',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'stock_applied' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** Scans or photos of the supplier's receipt, delivery note or invoice. */
    public function receipts(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')->where('type', 'receipt');
    }

    /** Whether this order's quantities belong in inventory. */
    public function countsAsStock(): bool
    {
        return $this->status !== self::CANCELLED;
    }
}
