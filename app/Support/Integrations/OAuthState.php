<?php

namespace App\Support\Integrations;

use Illuminate\Support\Facades\Crypt;

/**
 * The `state` of an OAuth round trip to Google or Meta: which restaurant and
 * which staff member started it, sealed so the callback - a public URL with
 * no session - can trust it. Encrypted (not just signed), so the ids are not
 * readable in the browser's address bar, and short-lived.
 */
final class OAuthState
{
    private const TTL = 900;

    public static function make(string $provider, int $tenantId, int $userId): string
    {
        return Crypt::encryptString(json_encode([
            'p' => $provider, 't' => $tenantId, 'u' => $userId, 'x' => time() + self::TTL, 'n' => bin2hex(random_bytes(8)),
        ]));
    }

    /** @return array{tenant_id: int, user_id: int}|null null when forged, expired or for another provider */
    public static function read(?string $state, string $provider): ?array
    {
        if (! is_string($state) || $state === '') {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($state), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($data) || ($data['p'] ?? null) !== $provider || ($data['x'] ?? 0) < time()) {
            return null;
        }

        return ['tenant_id' => (int) $data['t'], 'user_id' => (int) $data['u']];
    }
}
