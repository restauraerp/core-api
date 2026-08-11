<?php

namespace App\Support\Auth;

use App\Models\OneTimeLoginToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WebsiteNotifier;
use App\Support\Tenancy\TenantContext;
use RuntimeException;

/**
 * Sends somebody a link that lets them set a new password.
 *
 * Built on the one-time login token rather than a second token type of its
 * own: that mechanism already does exactly this job - one use, short life,
 * spent on redemption - and the front already has a screen for redeeming one
 * and choosing a password. A parallel implementation would be a second thing
 * to get the expiry and single-use semantics right on.
 *
 * Used by both routes into a reset: the customer asking for one themselves,
 * and an admin sending one on their behalf.
 */
class PasswordResetLink
{
    public function __construct(
        private WebsiteNotifier $notifier,
        private TenantContext $context,
    ) {}

    /**
     * Whether this account may be reset at all.
     *
     * The demo account is deliberately excluded. Its address and password are
     * published on the marketing site for anyone to try, so a reset link is
     * both pointless - everybody already has the credentials - and an open
     * invitation: any passer-by could lock every other visitor out of the demo
     * by resetting it.
     */
    public function isResettable(User $user, ?Tenant $tenant = null): bool
    {
        $tenant ??= Tenant::withoutGlobalScopes()->find($user->tenant_id);

        if (! config('app.demo_mode')) {
            return true;
        }

        if ($tenant !== null && $tenant->slug === config('app.demo_tenant_slug')) {
            return false;
        }

        return ! hash_equals(
            (string) config('app.demo_username'),
            (string) $user->email,
        );
    }

    /**
     * Mints a link and asks the website to email it.
     *
     * Returns false when the account is one we refuse to reset. The caller is
     * expected NOT to tell the customer which it was: see the controller.
     */
    public function send(User $user, ?Tenant $tenant = null): bool
    {
        $tenant ??= Tenant::withoutGlobalScopes()->find($user->tenant_id);

        if (! $this->isResettable($user, $tenant)) {
            return false;
        }

        $appUrl = rtrim((string) config('platform.app_url'), '/');

        if ($appUrl === '') {
            throw new RuntimeException(
                'FRONTEND_URL is not set, so no password reset link can be built.'
            );
        }

        // Writing the token needs the tenant in context: OneTimeLoginToken is
        // tenant-scoped, and this runs on a public route where nothing has
        // resolved a tenant yet.
        $plain = $tenant === null
            ? OneTimeLoginToken::issueFor($user)
            : $this->context->runFor($tenant, fn () => OneTimeLoginToken::issueFor($user));

        // Forces the choose-a-password screen after redemption. Without it the
        // link is a plain login, and somebody who came here because they had
        // forgotten their password would be dropped into the app still not
        // knowing it.
        $user->forceFill(['must_set_password' => true])->save();

        $this->notifier->passwordReset(
            email: $user->email,
            name: $user->name,
            resetUrl: $appUrl.'/login/one-time?token='.urlencode($plain),
            expiresInHours: min(
                (int) config('platform.login_link_ttl_hours', 24),
                OneTimeLoginToken::MAX_TTL_HOURS,
            ),
            restaurantName: $tenant?->name,
        );

        return true;
    }
}
