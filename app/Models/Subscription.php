<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'plan_id',
        'current_cycle_start',
        'current_cycle_end',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'current_cycle_start' => 'date',
            'current_cycle_end' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(SubscriptionSegment::class)->orderBy('starts_at');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Transition plan mid-cycle (Requirement 8)
     */
    public function transitionPlan(Plan $newPlan, Carbon|string $transitionTime): void
    {
        $transition = Carbon::parse($transitionTime);
        $cycleStart = Carbon::parse($this->current_cycle_start)->startOfDay();
        $cycleEnd = Carbon::parse($this->current_cycle_end)->endOfDay();
        $totalCycleSeconds = max(1, $cycleEnd->timestamp - $cycleStart->timestamp);

        // Find the active segment contains transition time
        $currentSegment = $this->segments()
            ->where('starts_at', '<=', $transition)
            ->where('ends_at', '>=', $transition)
            ->first();

        if ($currentSegment) {
            // Short segment
            $seg1Duration = max(0, $transition->timestamp - Carbon::parse($currentSegment->starts_at)->timestamp);
            $seg1Ratio = $seg1Duration / $totalCycleSeconds;
            $oldPlan = $currentSegment->plan;

            $currentSegment->update([
                'ends_at' => $transition,
                'prorated_base_price' => round($oldPlan->base_price * $seg1Ratio, 2),
                'prorated_included_units' => (int) round($oldPlan->included_usage_units * $seg1Ratio),
            ]);
        }

        // Create new segment for the remainder of the cycle
        $seg2Duration = max(0, $cycleEnd->timestamp - $transition->timestamp);
        $seg2Ratio = $seg2Duration / $totalCycleSeconds;

        $this->segments()->create([
            'plan_id' => $newPlan->id,
            'starts_at' => $transition,
            'ends_at' => $cycleEnd,
            'prorated_base_price' => round($newPlan->base_price * $seg2Ratio, 2),
            'prorated_included_units' => (int) round($newPlan->included_usage_units * $seg2Ratio),
            'overage_rate_per_unit' => $newPlan->overage_rate_per_unit,
        ]);

        $this->update(['plan_id' => $newPlan->id]);
    }
}
