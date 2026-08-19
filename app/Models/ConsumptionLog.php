<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConsumptionLog extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'inventory_item_id',
        'location_id',
        'quantity',
        'reason',
        'consumed_at',
        'entry_unit',
        'stock_quantity',
        'logged_by',
    ];

    protected $casts = [
        'consumed_at' => 'date',
        'quantity'    => 'decimal:3',
        'stock_quantity' => 'decimal:4',
        'edited_at'   => 'datetime',
        'original_quantity' => 'decimal:3',
    ];

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function loggedBy()
    {
        return $this->belongsTo(User::class, 'logged_by');
    }

    public function trashedByUser()
    {
        return $this->belongsTo(User::class, 'trashed_by');
    }

    /** Whoever last corrected this log. See ConsumptionLogController::update. */
    public function editedByUser()
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /**
     * What this log actually moved off the shelf, in purchase units.
     *
     * Falls back to `quantity` for rows written before the sale unit existed,
     * where the two were the same thing by definition.
     */
    public function stockQuantity(): float
    {
        return (float) ($this->stock_quantity ?? $this->quantity);
    }
}
