<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Plan;
use App\Services\PlanCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PlanCacheTest extends TestCase
{
    use RefreshDatabase;

    protected Merchant $merchant;
    protected Plan $plan;
    protected PlanCacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create([
            'name' => 'Acme Corp',
            'email' => 'acme@example.com',
            'api_key' => 'cache_test_key',
            'currency' => 'INR',
        ]);

        $this->plan = Plan::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Growth',
            'code' => 'growth-monthly',
            'base_price' => 15000.00,
            'billing_cycle' => 'monthly',
            'included_usage_units' => 250000,
            'overage_rate_per_unit' => 0.50,
            'is_active' => true,
        ]);

        $this->cacheService = app(PlanCacheService::class);
    }

    /**
     * Test plan lookup is cached.
     */
    public function test_plan_pricing_lookup_is_cached(): void
    {
        $cacheKey = $this->cacheService->getPlanKey($this->plan->id);
        $this->assertFalse(Cache::has($cacheKey));

        // First call caches the plan
        $plan1 = $this->cacheService->getPlan($this->plan->id);
        $this->assertEquals('Growth', $plan1->name);
        $this->assertTrue(Cache::has($cacheKey));

        // Second call retrieves from cache
        $plan2 = $this->cacheService->getPlan($this->plan->id);
        $this->assertEquals($plan1->id, $plan2->id);
    }

    /**
     * Test plan update invalidates the cache key.
     */
    public function test_plan_update_triggers_cache_invalidation(): void
    {
        $cacheKey = $this->cacheService->getPlanKey($this->plan->id);

        // Prime cache
        $this->cacheService->getPlan($this->plan->id);
        $this->assertTrue(Cache::has($cacheKey));

        // Updating plan triggers model event hook
        $this->plan->update(['base_price' => 18000.00]);

        // Key should be evicted
        $this->assertFalse(Cache::has($cacheKey));

        // Subsequent fetch gets fresh price
        $freshPlan = $this->cacheService->getPlan($this->plan->id);
        $this->assertEquals(18000.00, $freshPlan->base_price);
    }
}
