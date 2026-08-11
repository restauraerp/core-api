<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A signed-in trial owner's request to go and pay.
 *
 * Short-lived on purpose: it is handed straight to a redirect, so it only has
 * to survive one hop to the website.
 */
class UpgradeToken extends Model
{
    /** Long enough for a redirect, short enough that a shared URL is useless. */
    public const TTL_MINUTES = 30;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'token_hash',
        'expires_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function issueFor(User $user): string
    {
        $plain = Str::random(64);

        static::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', $plain),
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES),
        ]);

        return $plain;
    }

    public static function findLive(string $plain): ?self
    {
        return static::query()
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();
    }

    /**
     * Spends the token. Conditional, so two simultaneous redemptions cannot
     * both succeed.
     */
    public function redeem(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => Carbon::now()]) === 1;
    }
}
