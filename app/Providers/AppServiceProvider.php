<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant per request / per console command.
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Platform admins pass every permission check regardless of the tenant
        // currently in context.
        //
        // This lives outside Spatie because Spatie's teams mode cannot express
        // it: model_has_roles.tenant_id is part of the composite primary key, so
        // a role assignment is always bound to exactly one tenant. The
        // super_admin *role* is the label; this flag is the authority.
        //
        // Returning null (not false) on the non-admin path is important - it
        // means "no opinion" and lets the normal Spatie check run.
        Gate::before(function (User $user, string $ability) {
            return $user->is_platform_admin ? true : null;
        });
    }
}
