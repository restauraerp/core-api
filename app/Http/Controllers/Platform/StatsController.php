<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Billing\Subscription;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Billing state, counted, for the marketing site's daily report.
 *
 * These numbers only exist here: the website holds orders and customers, but
 * what a subscription is worth, when it runs out and whether it is inside its
 * grace period is this side's business. The report is composed over there and
 * pulls this in.
 */
class StatsController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // The report's own idea of "today", so both halves agree on where
            // the day starts. The website runs on Asia/Dhaka; this server does
            // not, and a day boundary computed twice in two timezones is how a
            // report ends up disagreeing with itself.
            'timezone' => ['nullable', 'timezone'],
            'due_within_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $zone = $validated['timezone'] ?? config('app.timezone');
        $dueWithin = (int) ($validated['due_within_days'] ?? 7);

        $now = Carbon::now();

        // The day is decided in the caller's timezone, then expressed in the
        // one the database stores - which is the app timezone, not UTC. This
        // application runs on Asia/Dhaka and writes wall-clock values, so
        // comparing a UTC boundary against them is wrong by six hours and
        // silently reports the wrong day's worth of expiries.
        $storage = config('app.timezone');
        $todayStart = Carbon::now($zone)->startOfDay()->setTimezone($storage);
        $todayEnd = Carbon::now($zone)->endOfDay()->setTimezone($storage);

        // Cross-tenant by definition, and this endpoint runs outside tenant
        // resolution, so the whole thing is built unscoped.
        return response()->json($this->context->runWithoutScoping(function () use ($now, $todayStart, $todayEnd, $dueWithin, $zone) {
            return [
                'timezone' => $zone,
                'generated_at' => $now->toIso8601String(),

                // Forward-looking: what needs attention today or soon.
                'trials_ending_today' => Tenant::where('status', 'trialing')
                    ->whereBetween('trial_ends_at', [$todayStart, $todayEnd])
                    ->count(),

                'subscriptions_ending_today' => Tenant::where('status', 'active')
                    ->whereBetween('subscription_ends_at', [$todayStart, $todayEnd])
                    ->count(),

                'payments_due_within_days' => $dueWithin,
                'payments_due_soon' => Tenant::where('status', 'active')
                    ->whereBetween('subscription_ends_at', [$now, $now->copy()->addDays($dueWithin)])
                    ->count(),

                'in_grace' => $this->inGrace(),

                // Standing totals, for the shape of the business rather than
                // the shape of the day.
                'active_subscribers' => Tenant::where('status', 'active')->count(),
                'on_trial' => Tenant::where('status', 'trialing')->count(),
                'suspended' => Tenant::where('status', 'suspended')->count(),
                'cancelled' => Tenant::where('status', 'cancelled')->count(),
                'restaurants_total' => Tenant::count(),

                // Trials that have run out and were never paid for. The single
                // most actionable list on here: they used the product and
                // stopped, and nobody is chasing them.
                'trials_expired_unconverted' => Tenant::where('status', 'trialing')
                    ->whereNotNull('trial_ends_at')
                    ->where('trial_ends_at', '<', $now)
                    ->count(),
            ];
        }));
    }

    /**
     * Subscriptions that have run out but are still inside their grace period.
     *
     * Counted in PHP rather than SQL because the grace window depends on the
     * billing cycle, and the alternative is a CASE expression that has to be
     * kept in step with Subscription::graceDays by hand.
     */
    private function inGrace(): int
    {
        $now = Carbon::now();

        return Tenant::where('status', 'active')
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '<', $now)
            ->get(['id', 'billing_cycle', 'subscription_ends_at'])
            ->filter(fn (Tenant $tenant) => $tenant->subscription_ends_at
                ->copy()
                ->addDays(Subscription::graceDays($tenant->billing_cycle))
                ->isFuture())
            ->count();
    }
}
