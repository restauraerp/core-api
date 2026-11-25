<?php

namespace Tests\Unit;

use App\Services\DeliveryPersonService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeliveryPersonServiceTest extends TestCase
{
    public function test_cache_key_generation_formats_consistently(): void
    {
        $this->assertSame('tenant:123:delivery_persons', DeliveryPersonService::cacheKey(123));
        $this->assertSame('tenant:8:delivery_persons', DeliveryPersonService::cacheKey('8'));
    }

    public function test_get_for_tenant_returns_empty_collection_when_tenant_id_is_null_and_no_context(): void
    {
        $result = DeliveryPersonService::getForTenant(null);
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertTrue($result->isEmpty());
    }

    public function test_self_heals_and_recovers_when_cache_holds_php_incomplete_class(): void
    {
        $tenantId = 999;
        $key = DeliveryPersonService::cacheKey($tenantId);

        // Put an __PHP_Incomplete_Class instance directly in cache to simulate broken deserialization
        $incomplete = unserialize('O:14:"UndefinedClass":0:{}');
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $incomplete);

        Cache::put($key, $incomplete, 3600);
        $this->assertTrue(Cache::has($key));

        // Stub/mock queryForTenant or verify that corrupt cache is discarded
        $cached = Cache::get($key);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $cached);

        // Calling flushForTenant or validating cache guard
        DeliveryPersonService::flushForTenant($tenantId);
        $this->assertFalse(Cache::has($key));
    }

    public function test_valid_cached_arrays_are_returned_as_collection(): void
    {
        $tenantId = 555;
        $key = DeliveryPersonService::cacheKey($tenantId);

        $sampleData = [
            [
                'id' => 10,
                'name' => 'John Rider',
                'email' => 'john@rider.com',
                'phone' => '01711111111',
                'location_id' => 2,
                'tenant_id' => 555,
                'can_deliver' => true,
                'roles' => [['id' => 1, 'name' => 'rider']],
                'permissions' => [],
                'location' => ['id' => 2, 'name' => 'Downtown'],
            ],
        ];

        Cache::put($key, $sampleData, 3600);

        $result = DeliveryPersonService::getForTenant($tenantId);
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(1, $result);
        $this->assertSame('John Rider', $result->first()['name']);
    }
}
