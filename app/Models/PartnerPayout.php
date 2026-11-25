<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money actually received from a partner.
 *
 * Can be recorded as a lump sum or linked directly to a batch of settled orders.
 */
class PartnerPayout extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'partner_id',
        'amount',
        'payment_method',
        'cash_storage_id',
        'orders_count',
        'received_on',
        'reference',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'orders_count' => 'integer',
            'received_on' => 'date',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function cashStorage(): BelongsTo
    {
        return $this->belongsTo(CashStorage::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
