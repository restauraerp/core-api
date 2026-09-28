<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A single-use onboarding link for one rider.
 *
 * The raw token travels in the t.me link's start parameter; only its hash is
 * stored, so a database read cannot link somebody else's Telegram to a rider.
 */
class TelegramLinkToken extends Model
{
    use BelongsToTenant;

    /** How long a copied link stays good. Long enough to send it over WhatsApp and wait. */
    public const TTL_HOURS = 72;

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Issues a fresh link for a rider, retiring any earlier one.
     *
     * 43 base64url characters: inside Telegram's 64-character limit for a
     * start parameter and far beyond guessing.
     */
    public static function issueFor(User $user): string
    {
        static::where('user_id', $user->getKey())->whereNull('used_at')->delete();

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        static::create([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);

        return $token;
    }

    /** The live token matching a raw value, if any. */
    public static function findUsable(string $token): ?self
    {
        if ($token === '' || strlen($token) > 64 || ! preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            return null;
        }

        return static::where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
    }
}
