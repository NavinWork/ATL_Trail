<?php

namespace App\Models;

use App\Services\PlanCacheService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'name',
        'code',
        'base_price',
        'billing_cycle',
        'included_usage_units',
        'overage_rate_per_unit',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'included_usage_units' => 'integer',
            'overage_rate_per_unit' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    protected static function booted(): void
    {
        static::saved(function (Plan $plan) {
            app(PlanCacheService::class)->invalidatePlan($plan->id);
            app(PlanCacheService::class)->invalidateMerchantPlans($plan->merchant_id);
        });

        static::deleted(function (Plan $plan) {
            app(PlanCacheService::class)->invalidatePlan($plan->id);
            app(PlanCacheService::class)->invalidateMerchantPlans($plan->merchant_id);
        });
    }
}
