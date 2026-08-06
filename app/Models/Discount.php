<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use BelongsToTenant;

    /**
     * tenant_id is deliberately absent - BelongsToTenant stamps it, and
     * listing it here would let a request body move this row to another
     * restaurant.
     */
    protected $fillable = [
        'code',
        'discount_type',
        'value',
        'valid_until',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'valid_until' => 'datetime',
            'is_active' => 'boolean',
            'value' => 'decimal:2',
        ];
    }
}
