<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tells a client what the demo restaurant's credentials are.
 *
 * This exists so demo mode is a property of the API, discovered at request
 * time, rather than something each client bakes in at build time. The front
 * used to carry NEXT_PUBLIC_IS_DEMO/_DEMO_EMAIL/_DEMO_PASSWORD in its bundle,
 * which meant the credentials shipped to every visitor of every deployment and
 * changing them required a rebuild. Now the front asks here, only when a
 * visitor arrives at /login?demo=true.
 *
 * Yes, this publishes a password unauthenticated - that is the point of a demo
 * whose credentials are printed on the marketing site anyway. The safeguard is
 * that app.demo_mode defaults to false, so a customer install answers 404.
 */
class DemoController extends Controller
{
    public function show(): JsonResponse
    {
        // 404, not 403: a disabled demo should not confirm that the endpoint
        // exists, and there is nothing here for a caller to authenticate into.
        if (! config('app.demo_mode')) {
            throw new NotFoundHttpException;
        }

        return response()->json([
            // The "restaurant code" the login form needs alongside the
            // credentials - emails are unique per tenant, not platform-wide.
            // Stable across refreshes; the demo tenant's numeric id is not.
            'tenant' => config('app.demo_tenant_slug'),
            'email' => config('app.demo_username'),
            'password' => config('app.demo_password'),
        ]);
    }
}
