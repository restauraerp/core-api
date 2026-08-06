<?php

namespace App\Models;

use App\Support\Billing\Plans;
use App\Support\Billing\Subscription;
use Carbon\CarbonInterface;
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
        'billing_cycle',
        'status',
        'max_outlets',
        'contact_name',
        'contact_email',
        'contact_phone',
        'trial_ends_at',
        'subscription_ends_at',
    ];

    /**
     * Outlet caps per plan, per config/plans.php. NULL = unlimited.
     *
     * Kept as a property rather than a const because the caps now live in
     * config - the tiers are a commercial fact that changes without a release.
     *
     * @return array<string, int|null>
     */
    public static function planOutletLimits(): array
    {
        return Plans::outletLimits();
    }

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
     * Whether this tenant may serve API traffic at all. Suspended, cancelled
     * and lapsed tenants are rejected by ResolveTenant before any controller
     * runs.
     *
     * Expiry is evaluated here rather than left to `tenants:expire` alone: the
     * command runs once a day, and a trial that ends at midnight should not
     * keep serving until the next sweep. The command exists to make `status`
     * tell the truth afterwards, not to be the gate.
     */
    public function isActive(): bool
    {
        if (! in_array($this->status, ['trialing', 'active'], true)) {
            return false;
        }

        return ! $this->hasLapsed();
    }

    /**
     * Whether the paid period (or the trial, if still trialing) has run out.
     */
    public function hasLapsed(): bool
    {
        $expiresAt = $this->status === 'trialing'
            ? $this->trial_ends_at
            : $this->subscription_ends_at;

        // NULL means "no end date recorded" - an open-ended account, not an
        // expired one. Treating it as expired would lock out every tenant
        // created before billing dates were tracked.
        return $expiresAt !== null && $expiresAt->isPast();
    }

    /**
     * @return array{state: string, reason: ?string, expires_at: ?CarbonInterface, grace_ends_at: ?CarbonInterface, cycle: ?string}
     */
    public function subscription(): array
    {
        return Subscription::for($this);
    }

    /**
     * Cancelled only. Everything else - expired trial, lapsed subscription,
     * suspended - can still log in and read; it just cannot write.
     */
    public function isBlocked(): bool
    {
        return $this->subscription()['state'] === Subscription::BLOCKED;
    }

    public function isReadOnly(): bool
    {
        return $this->subscription()['state'] === Subscription::READ_ONLY;
    }

    public function isInGrace(): bool
    {
        return $this->subscription()['state'] === Subscription::GRACE;
    }

    public function planLabel(): string
    {
        return Plans::exists((string) $this->plan)
            ? Plans::label((string) $this->plan)
            : (string) $this->plan;
    }

    /**
     * The outlet cap in force: the per-tenant override if one is set, otherwise
     * the tier's. NULL means unlimited.
     *
     * max_outlets is kept as a column so support can grant an exception to a
     * single restaurant without inventing a tier for them.
     */
    public function outletLimit(): ?int
    {
        if ($this->max_outlets !== null) {
            return (int) $this->max_outlets;
        }

        return Plans::exists((string) $this->plan)
            ? Plans::outletLimit((string) $this->plan)
            : null;
    }

    public function hasReachedOutletLimit(): bool
    {
        $limit = $this->outletLimit();

        if ($limit === null) {
            return false;
        }

        return $this->locations()->count() >= $limit;
    }

    /**
     * Whether this tenant's tier includes a module. Unknown tiers are treated
     * as entitled to everything: a plan value the config does not know about is
     * a deployment mistake, and locking a paying restaurant out of its own data
     * is the worse failure.
     */
    public function hasModule(string $module): bool
    {
        if (! Plans::exists((string) $this->plan)) {
            return true;
        }

        return Plans::includesModule((string) $this->plan, $module);
    }
}
