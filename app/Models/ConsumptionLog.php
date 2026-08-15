<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ConsumptionLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'inventory_item_id',
        'location_id',
        'quantity',
        'reason',
        'consumed_at',
        'logged_by',
    ];

    protected $casts = [
        'consumed_at' => 'date',
        'quantity'    => 'decimal:3',
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
}
