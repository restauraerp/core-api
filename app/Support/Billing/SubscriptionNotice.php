<?php

namespace App\Support\Billing;

use App\Models\Tenant;

/**
 * The body returned when billing state refuses something.
 *
 * These messages are read by a restaurant manager mid-shift who has just been
 * told "no" by software they pay for, so each one says three things: what
 * happened, what still works, and who to call. A bare "Forbidden" turns a
 * billing problem into a support call about a bug.
 *
 * Machine-readable `error` codes are stable and safe for a client to branch on;
 * the prose is not.
 */
class SubscriptionNotice
{
    /**
     * Cancelled - nothing is served.
     *
     * @return array<string, mixed>
     */
    public static function blocked(Tenant $tenant): array
    {
        return self::body(
            'subscription_cancelled',
            'This account has been cancelled and can no longer be accessed. '
                .'If this is unexpected, please contact us - your data has not been deleted.',
            $tenant,
        );
    }

    /**
     * Read-only: the write was refused, but the account still works otherwise.
     *
     * @return array<string, mixed>
     */
    public static function readOnly(Tenant $tenant): array
    {
        $subscription = $tenant->subscription();
        $expired = $subscription['expires_at']?->toFormattedDateString();

        [$code, $message] = match ($subscription['reason']) {
            'trial_expired' => [
                'trial_expired',
                "Your free trial ended on {$expired}, so new data cannot be saved. "
                    .'Everything you already entered is still here and fully readable - '
                    .'subscribe and it picks up exactly where it left off.',
            ],
            'subscription_expired' => [
                'subscription_expired',
                self::expiredMessage($subscription, $expired),
            ],
            default => [
                'account_suspended',
                'This account is suspended, so new data cannot be saved. '
                    .'Your existing records remain readable. Please contact us to restore full access.',
            ],
        };

        return self::body($code, $message, $tenant, [
            'read_only' => true,
            // Spelled out because the message alone cannot be branched on: the
            // client needs to know reads still work to render a banner rather
            // than an error page.
            'reads_allowed' => true,
            'writes_allowed' => false,
        ]);
    }

    private static function expiredMessage(array $subscription, ?string $expired): string
    {
        $cycle = $subscription['cycle'] === 'yearly' ? 'yearly' : 'monthly';
        $graceDays = Subscription::graceDays($subscription['cycle']);
        $graceEnded = $subscription['grace_ends_at']?->toFormattedDateString();

        return "Your {$cycle} subscription ended on {$expired} and the {$graceDays}-day "
            ."grace period ran out on {$graceEnded}, so new data cannot be saved. "
            .'Everything you have already entered is still here and fully readable - '
            .'renew and saving resumes immediately.';
    }

    /**
     * Still inside the grace window. Not an error - returned as a warning
     * alongside a successful write so the client can nag before anything
     * actually stops working.
     *
     * @return array<string, mixed>
     */
    public static function grace(Tenant $tenant): array
    {
        $subscription = $tenant->subscription();
        $days = (int) ceil(now()->diffInDays($subscription['grace_ends_at'], absolute: true));

        return self::body(
            'subscription_in_grace',
            "Your subscription ended on {$subscription['expires_at']?->toFormattedDateString()}. "
                ."You have {$days} day(s) left to renew before this restaurant becomes read-only.",
            $tenant,
            [
                'read_only' => false,
                'grace_ends_at' => $subscription['grace_ends_at']?->toIso8601String(),
                'days_remaining' => $days,
            ],
        );
    }

    /**
     * The current state, for a client to render on load rather than after a
     * refused save. Returned by GET /auth/me.
     *
     * @return array<string, mixed>
     */
    public static function status(Tenant $tenant): array
    {
        $subscription = $tenant->subscription();

        return match ($subscription['state']) {
            Subscription::READ_ONLY => self::readOnly($tenant),
            Subscription::GRACE => self::grace($tenant),
            Subscription::BLOCKED => self::blocked($tenant),
            default => [
                'error' => null,
                'message' => null,
                'read_only' => false,
                'state' => Subscription::FULL,
                'expired_at' => $subscription['expires_at']?->toIso8601String(),
                'billing_cycle' => $subscription['cycle'],
                // A healthy subscription needs describing too, not just a
                // failing one: the profile screen tells a manager which package
                // they are on and when it next falls due. Before this, those
                // fields only existed on the refusal bodies, so the one screen
                // that should always show them could never see them.
                'plan' => $tenant->plan,
                'plan_name' => $tenant->planLabel(),
                'contact' => self::contact(),
            ],
        } + [
            'state' => $subscription['state'],
            // A running trial reports state `full` - it is not in trouble - so
            // the raw tenant status is the only thing that tells a client it is
            // a trial at all. The upgrade prompt in the app needs to know.
            'tenant_status' => $tenant->status,
            // Whether this really is the demo restaurant. The front cannot work
            // this out for itself: it only has a cookie set by ?demo=true, which
            // survives for a day and follows the visitor into their own account
            // once they sign up. Answering it here is what stops a paying
            // customer being told they are looking at a demo.
            'is_demo' => (bool) config('app.demo_mode')
                && $tenant->slug === config('app.demo_tenant_slug'),
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            'trial_days_remaining' => $tenant->status === 'trialing' && $tenant->trial_ends_at !== null
                ? max(0, (int) ceil(now()->diffInDays($tenant->trial_ends_at, absolute: false)))
                : null,
            // Named for what it is on a live account: the day the paid period
            // runs out, which is also the day the next payment is due. The
            // older `expired_at` says the same thing but reads as past tense,
            // and clients already depend on it, so both are sent.
            'expires_at' => $subscription['expires_at']?->toIso8601String(),
            // How long saving keeps working past that date. Sent even while
            // healthy so a client can say "due on the 3rd, stops on the 10th"
            // rather than springing the deadline once it has passed.
            'grace_ends_at' => $subscription['grace_ends_at']?->toIso8601String()
                ?? $subscription['expires_at']?->copy()
                    ->addDays(Subscription::graceDays($subscription['cycle']))
                    ->toIso8601String(),
            'grace_days' => Subscription::graceDays($subscription['cycle']),
            // Whole days until the paid period ends; negative once it has.
            'days_until_expiry' => $subscription['expires_at'] === null
                ? null
                : (int) ceil(now()->diffInDays($subscription['expires_at'], absolute: false)),
        ];
    }

    /**
     * Who to talk to about billing. Shared by the refusal bodies and the
     * healthy status, so the profile screen can offer the same routes out
     * without waiting for something to go wrong first.
     *
     * @return array<string, string>
     */
    private static function contact(): array
    {
        return array_filter([
            'email' => config('support.email'),
            'phone' => config('support.phone'),
            'whatsapp' => config('support.whatsapp'),
            'url' => config('support.billing_url'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function body(string $code, string $message, Tenant $tenant, array $extra = []): array
    {
        $subscription = $tenant->subscription();

        return array_merge([
            'error' => $code,
            'message' => $message,
            'plan' => $tenant->plan,
            'plan_name' => $tenant->planLabel(),
            'billing_cycle' => $subscription['cycle'],
            'expired_at' => $subscription['expires_at']?->toIso8601String(),
            'contact' => self::contact(),
        ], $extra);
    }
}
