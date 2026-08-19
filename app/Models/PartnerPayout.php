<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Money actually received from a partner.
 *
 * Recorded as it arrives rather than matched to particular orders. An
 * aggregator pays a fortnight of trading in one transfer, net of its own
 * adjustments, and insisting each payout be reconciled line by line against
 * individual orders would be bookkeeping the restaurant does not do and cannot
 * check. What matters is the running balance: earned, less received.
 */
class PartnerPayout extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'partner_id',
        'amount',
        'received_on',
        'reference',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_on' => 'date',
        ];
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }
}
