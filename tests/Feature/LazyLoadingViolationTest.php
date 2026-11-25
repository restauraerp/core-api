<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LazyLoadingViolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function lazy_loading_triggers_warning_when_relation_is_not_eager_loaded(): void
    {
        Log::spy();

        $tenant = Tenant::factory()->create(['slug' => 'lazy-test']);
        [$user1, $user2] = app(TenantContext::class)->runFor($tenant, function () use ($tenant) {
            return [
                User::factory()->create(['tenant_id' => $tenant->getKey()]),
                User::factory()->create(['tenant_id' => $tenant->getKey()]),
            ];
        });

        // Ensure lazy loading restriction is active
        Model::preventLazyLoading(true);
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
            $class = get_class($model);
            $key = $model->getKey();
            Log::warning(
                "N+1 Query Lazy Loading Detected: Attempted to lazy load [{$relation}] on [{$class}] (ID: {$key}). ".
                "Eager load this relation with 'with()' to optimize query performance."
            );
        });

        // Query multiple users without eager loading tenant
        $users = User::whereIn('id', [$user1->getKey(), $user2->getKey()])->get();
        $this->assertCount(2, $users);

        // Access the relation lazily across the collection
        foreach ($users as $user) {
            $relatedTenant = $user->tenant;
            $this->assertNotNull($relatedTenant);
            $this->assertSame($tenant->getKey(), $relatedTenant->getKey());
        }

        // Verify warning was logged with relation and model info
        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->withArgs(function (string $message) {
                return str_contains($message, 'N+1 Query Lazy Loading Detected')
                    && str_contains($message, '[tenant]')
                    && str_contains($message, '['.User::class.']');
            });
    }

    #[Test]
    public function eager_loading_prevents_lazy_loading_warning(): void
    {
        Log::spy();

        $tenant = Tenant::factory()->create(['slug' => 'eager-test']);
        [$user1, $user2] = app(TenantContext::class)->runFor($tenant, function () use ($tenant) {
            return [
                User::factory()->create(['tenant_id' => $tenant->getKey()]),
                User::factory()->create(['tenant_id' => $tenant->getKey()]),
            ];
        });

        Model::preventLazyLoading(true);
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
            $class = get_class($model);
            $key = $model->getKey();
            Log::warning(
                "N+1 Query Lazy Loading Detected: Attempted to lazy load [{$relation}] on [{$class}] (ID: {$key}). ".
                "Eager load this relation with 'with()' to optimize query performance."
            );
        });

        // Query with eager loading
        $users = User::with('tenant')->whereIn('id', [$user1->getKey(), $user2->getKey()])->get();
        $this->assertCount(2, $users);

        // Access already eager-loaded relation
        foreach ($users as $user) {
            $relatedTenant = $user->tenant;
            $this->assertNotNull($relatedTenant);
            $this->assertSame($tenant->getKey(), $relatedTenant->getKey());
        }

        // Verify no warning was logged
        Log::shouldNotHaveReceived('warning');
    }
}
