<?php

namespace App\Providers;

use App\Models\User;
use App\Services\DeliveryPersonService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

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
        // Restrict lazy loading outside production to detect and prevent N+1 query regressions.
        // Reports via a structured warning in logs rather than crashing the request.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
            $class = get_class($model);
            $key = $model->getKey();
            Log::warning(
                "N+1 Query Lazy Loading Detected: Attempted to lazy load [{$relation}] on [{$class}] (ID: {$key}). ".
                "Eager load this relation with 'with()' to optimize query performance."
            );
        });

        // Monitor slow queries in non-production environments and log actionable warnings
        if (! $this->app->isProduction()) {
            DB::listen(function ($query) {
                $threshold = (float) config('database.slow_query_threshold', 200.0);
                if ($query->time > $threshold) {
                    Log::warning(
                        "[SLOW QUERY DETECTED] Execution time: {$query->time}ms | SQL: {$query->sql} | Bindings: ".
                        json_encode($query->bindings)
                    );
                }
            });
        }

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

        // website_settings hold both the restaurant's identity and settings
        // (Settings screen) and its website content (Website screen), so
        // either right may write them.
        Gate::define(
            'manage_site_settings',
            fn (User $user) => $user->hasPermissionTo('manage_system_settings')
                || $user->hasPermissionTo('manage_website_content')
        );

        // Rider notifications: each restaurant's bot sends at most
        // config('telegram.per_second') a second and per_minute a minute.
        // Distinct keys per window - two limits sharing a key would count
        // against each other.
        RateLimiter::for('telegram', fn (object $job) => [
            Limit::perSecond(max(1, (int) config('telegram.per_second', 1)))->by('telegram-s:'.$job->tenantId),
            Limit::perMinute(max(1, (int) config('telegram.per_minute', 30)))->by('telegram-m:'.$job->tenantId),
        ]);

        // When any role is saved or deleted, flush the tenant's delivery persons cache
        Role::saved(function ($role) {
            if (! empty($role->tenant_id)) {
                DeliveryPersonService::flushForTenant((int) $role->tenant_id);
            }
        });
        Role::deleted(function ($role) {
            if (! empty($role->tenant_id)) {
                DeliveryPersonService::flushForTenant((int) $role->tenant_id);
            }
        });
    }
}
