<?php

namespace App\Http\Middleware;

use App\Support\Billing\Subscription;
use App\Support\Billing\SubscriptionNotice;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a lapsed restaurant read-only instead of shutting it out.
 *
 * The rule is the HTTP method, not the endpoint: anything that reads is
 * allowed, anything that writes is refused. That keeps the behaviour
 * predictable as routes are added - a new controller is covered the day it is
 * written, with nothing to remember.
 *
 * Login is the deliberate exception. It is a POST, and blocking it would mean a
 * customer could not get in to see the message telling them to pay - which is
 * the one thing they need to do.
 */
class EnforceSubscription
{
    /**
     * Methods that change something.
     */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Paths allowed to accept writes regardless of billing state.
     *
     * Authentication only. A lapsed tenant must be able to log in, look around
     * and log out; that is how they read the notice and how support verifies a
     * complaint.
     */
    private const ALWAYS_WRITABLE = [
        'api/v1/auth/login',
        'api/v1/auth/logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(TenantContext::class)->get();

        if ($tenant === null || ! $this->isWrite($request)) {
            return $this->withGraceWarning($next($request), $tenant);
        }

        if ($tenant->subscription()['state'] === Subscription::READ_ONLY) {
            return response()->json(SubscriptionNotice::readOnly($tenant), 403);
        }

        return $this->withGraceWarning($next($request), $tenant);
    }

    private function isWrite(Request $request): bool
    {
        if (! in_array($request->method(), self::WRITE_METHODS, true)) {
            return false;
        }

        foreach (self::ALWAYS_WRITABLE as $path) {
            if ($request->is($path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Attaches the countdown to successful responses while a subscription is in
     * grace, so the warning arrives before anything breaks rather than on the
     * first refused save.
     *
     * Added under a separate key so it cannot collide with a payload's own
     * fields, and only to JSON object responses - a list endpoint returning a
     * bare array is left alone rather than reshaped.
     */
    private function withGraceWarning(Response $response, $tenant): Response
    {
        if ($tenant === null || ! $response instanceof JsonResponse || $response->isServerError()) {
            return $response;
        }

        if ($tenant->subscription()['state'] !== Subscription::GRACE) {
            return $response;
        }

        $data = $response->getData(true);

        if (! is_array($data) || array_is_list($data)) {
            return $response;
        }

        $data['subscription_warning'] = SubscriptionNotice::grace($tenant);

        return $response->setData($data);
    }
}
