<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A link that buys exactly one login.
 *
 * Deliberately not using BelongsToTenant: a token is presented before anything
 * about the caller is known, so it has to be findable without a tenant already
 * in context. It carries its own tenant_id instead, which redemption uses to
 * establish that context.
 */
class OneTimeLoginToken extends Model
{
    protected $fillable = [
        'user_id',
        'tenant_id',
        'token_hash',
        'expires_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Issues a token for a user and returns the plain text - the only moment it
     * exists in readable form.
     *
     * Any unspent token for the same user is expired first: handing out a
     * second link must invalidate the first, or a forwarded email stays live.
     */
    public static function issueFor(User $user, ?int $ttlHours = null): string
    {
        static::query()
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => Carbon::now()]);

        $plain = Str::random(64);

        static::create([
            'user_id' => $user->getKey(),
            'tenant_id' => $user->tenant_id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => Carbon::now()->addHours(
                $ttlHours ?? (int) config('platform.login_link_ttl_hours', 72),
            ),
        ]);

        return $plain;
    }

    /**
     * Finds a live token by its plain text.
     *
     * Looked up by hash rather than compared row by row, so this stays one
     * indexed query no matter how many tokens exist.
     */
    public static function findLive(string $plain): ?self
    {
        return static::query()
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();
    }

    /**
     * Spends the token. Returns false if something else got there first, which
     * is what makes a double-clicked link safe.
     */
    public function redeem(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => Carbon::now()]) === 1;
    }
}
