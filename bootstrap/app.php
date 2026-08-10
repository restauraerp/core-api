<?php

use App\Http\Middleware\EnforceSubscription;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePlatformToken;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs on every API request, including the public storefront endpoints
        // that have no auth middleware of their own.
        $middleware->api(append: [
            ResolveTenant::class,
            // After ResolveTenant, which is what puts the tenant in context.
            EnforceSubscription::class,
        ]);

        // Group order is not enough. Laravel sorts the resolved middleware
        // stack by $middlewarePriority, and AuthenticatesRequests (which
        // auth:sanctum implements) is in that list while our middleware is not
        // - so on every protected route auth:sanctum would run FIRST and 401
        // before a tenant was ever established. Public routes worked, protected
        // ones did not, which is a confusing way to find this out.
        //
        // Registering ResolveTenant immediately before AuthenticatesRequests
        // puts it back in front of authentication on every route.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: ResolveTenant::class,
        );

        // Same reasoning one step later: the subscription check needs the
        // tenant ResolveTenant establishes, and must still run ahead of the
        // route's own auth so a lapsed write is refused with a billing message
        // rather than reaching a controller.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: EnforceSubscription::class,
        );

        // Plan entitlement, applied per route group as `module:crm`.
        $middleware->alias([
            'module' => EnsureModuleEnabled::class,
            // Shared-secret auth for the website's server-to-server calls.
            'platform' => EnsurePlatformToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
