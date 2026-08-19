<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\NormalisesPhone;
use Illuminate\Database\Eloquent\Model;

/**
 * A third party that sends the restaurant orders and keeps a cut.
 *
 * Not a supplier and not a customer, though it looks a little like both: a
 * supplier is somebody the restaurant buys from, a customer is somebody who
 * eats there, and a partner is neither - it is a channel that owes money for
 * food already cooked and handed over.
 */
class Partner extends Model
{
    use BelongsToTenant, NormalisesPhone;

    protected $fillable = [
        'name',
        'contact_name',
        'phone',
        'email',
        'commission_rate',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function payouts()
    {
        return $this->hasMany(PartnerPayout::class);
    }
}
