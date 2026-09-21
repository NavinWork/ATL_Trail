<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class PlanCacheService
{
    /**
     * Cache TTL in seconds (10 minutes as specified in wireframe).
     */
    public const TTL_SECONDS = 600;

    /**
     * Get a cached plan by ID.
     */
    public function getPlan(int $planId): ?Plan
    {
        $cacheKey = $this->getPlanKey($planId);

        return Cache::remember($cacheKey, self::TTL_SECONDS, function () use ($planId) {
            return Plan::with('merchant')->find($planId);
        });
    }

    /**
     * Get active plans for a merchant.
     */
    public function getMerchantPlans(int $merchantId): Collection
    {
        $cacheKey = "merchant:{$merchantId}:active_plans";

        return Cache::remember($cacheKey, self::TTL_SECONDS, function () use ($merchantId) {
            return Plan::where('merchant_id', $merchantId)
                ->where('is_active', true)
                ->get();
        });
    }

    /**
     * Invalidate cached plan.
     */
    public function invalidatePlan(int $planId): void
    {
        Cache::forget($this->getPlanKey($planId));
    }

    /**
     * Invalidate merchant's cached plans list.
     */
    public function invalidateMerchantPlans(int $merchantId): void
    {
        Cache::forget("merchant:{$merchantId}:active_plans");
    }

    public function getPlanKey(int $planId): string
    {
        return "plan:pricing:{$planId}";
    }
}
