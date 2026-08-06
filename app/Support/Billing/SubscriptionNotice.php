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
            'contact' => array_filter([
                'email' => config('support.email'),
                'phone' => config('support.phone'),
                'whatsapp' => config('support.whatsapp'),
                'url' => config('support.billing_url'),
            ]),
        ], $extra);
    }
}
