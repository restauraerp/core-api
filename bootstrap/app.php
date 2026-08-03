<?php

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
            \App\Http\Middleware\ResolveTenant::class,
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
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\ResolveTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
