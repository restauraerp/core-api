<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One restaurant business.
 *
 * Deliberately does NOT use BelongsToTenant - this is the tenant itself, and
 * scoping it would make it invisible to its own global scope.
 */
class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'plan',
        'status',
        'max_outlets',
        'contact_name',
        'contact_email',
        'contact_phone',
        'trial_ends_at',
        'subscription_ends_at',
    ];

    /**
     * Outlet caps per plan, per docs/Pricing.md. NULL = unlimited.
     */
    public const PLAN_OUTLET_LIMITS = [
        'shared' => 2,
        'dedicated' => 5,
        'cloud' => null,
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function locations()
    {
        return $this->hasMany(Location::class);
    }

    /**
     * Whether this tenant may serve API traffic at all. Suspended and cancelled
     * tenants are rejected by ResolveTenant before any controller runs.
     */
    public function isActive(): bool
    {
        return in_array($this->status, ['trialing', 'active'], true);
    }

    public function hasReachedOutletLimit(): bool
    {
        if ($this->max_outlets === null) {
            return false;
        }

        return $this->locations()->count() >= $this->max_outlets;
    }
}
