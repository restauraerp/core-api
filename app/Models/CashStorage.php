<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashStorage extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    public const TYPE_CASH = 'cash';

    public const TYPE_CARD = 'card';

    public const TYPE_BKASH = 'bkash';

    public const TYPES = [
        self::TYPE_CASH,
        self::TYPE_CARD,
        self::TYPE_BKASH,
    ];

    protected $guarded = [];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Resolves the method string ('cash', 'card', 'mfs', 'bkash') to CashStorage type.
     */
    public static function normalizeType(string $method): string
    {
        $normalized = strtolower(trim($method));
        if ($normalized === 'mfs' || $normalized === 'bkash' || $normalized === 'nagad') {
            return self::TYPE_BKASH;
        }
        if ($normalized === 'card') {
            return self::TYPE_CARD;
        }

        return self::TYPE_CASH;
    }
}
