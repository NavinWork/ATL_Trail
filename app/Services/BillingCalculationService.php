<?php

namespace App\Services;

use App\Models\DailyUsageSummary;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionSegment;
use App\Models\UsageEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingCalculationService
{
    /**
     * Calculate prorated values for a given period within a billing cycle.
     *
     * @return array{ratio: float, prorated_base_price: float, prorated_units: int}
     */
    public function calculateProration(
        float $basePrice,
        int $includedUnits,
        Carbon|string $startDate,
        Carbon|string $endDate,
        Carbon|string $cycleStart,
        Carbon|string $cycleEnd
    ): array {
        $cStart = Carbon::parse($cycleStart)->startOfDay();
        $cEnd = Carbon::parse($cycleEnd)->startOfDay();
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate);

        $totalDays = max(1, $cStart->diffInDays($cEnd) + 1);

        // Check if spans full cycle
        if ($start->lte($cStart) && ($end->isEndOfDay() || $end->toDateString() >= $cEnd->toDateString())) {
            $ratio = 1.0;
        } else {
            // If end is exact start of transition day (exclusive)
            if ($end->isStartOfDay() && $end->toDateString() < $cEnd->toDateString()) {
                $activeDays = max(0, $start->diffInDays($end));
            } else {
                // Inclusive through end of period
                $activeDays = max(0, $start->diffInDays($end->copy()->startOfDay()) + 1);
            }
            $ratio = min(1.0, max(0.0, $activeDays / $totalDays));
        }

        return [
            'ratio' => round($ratio, 6),
            'prorated_base_price' => round($basePrice * $ratio, 2),
            'prorated_units' => (int) round($includedUnits * $ratio),
        ];
    }

    /**
     * Calculate overage for a given usage and allowance.
     *
     * @return array{overage_units: int, overage_charge: float}
     */
    public function calculateOverage(int $unitsConsumed, int $includedUnits, float $overageRate): array
    {
        $overageUnits = max(0, $unitsConsumed - $includedUnits);
        $overageCharge = round($overageUnits * $overageRate, 2);

        return [
            'overage_units' => $overageUnits,
            'overage_charge' => $overageCharge,
        ];
    }

    /**
     * Get usage units for a customer between two datetime boundaries.
     */
    public function getCustomerUsageBetween(int $customerId, Carbon|string $start, Carbon|string $end): int
    {
        $startDate = Carbon::parse($start);
        $endDate = Carbon::parse($end);

        // Check raw events first (useful for exact segment timestamps)
        $rawUnits = (int) UsageEvent::where('customer_id', $customerId)
            ->where('usage_date', '>=', $startDate->toDateTimeString())
            ->where('usage_date', '<=', $endDate->toDateTimeString())
            ->sum('units');

        if ($rawUnits > 0) {
            return $rawUnits;
        }

        // Fallback to daily summaries for the date range
        return (int) DailyUsageSummary::where('customer_id', $customerId)
            ->where('usage_date', '>=', $startDate->toDateString())
            ->where('usage_date', '<=', $endDate->toDateString())
            ->sum('total_units');
    }

    /**
     * Calculate and generate invoice for a subscription at cycle end.
     */
    public function generateInvoiceForSubscription(Subscription $subscription): Invoice
    {
        return DB::transaction(function () use ($subscription) {
            $customer = $subscription->customer;
            $cycleStart = Carbon::parse($subscription->current_cycle_start)->startOfDay();
            $cycleEnd = Carbon::parse($subscription->current_cycle_end)->endOfDay();

            $segments = $subscription->segments()
                ->with('plan')
                ->where('starts_at', '<=', $cycleEnd)
                ->where('ends_at', '>=', $cycleStart)
                ->orderBy('starts_at')
                ->get();

            // If no segment exists, create a default full-cycle segment from current plan
            if ($segments->isEmpty()) {
                $plan = $subscription->plan;
                $segment = $subscription->segments()->create([
                    'plan_id' => $plan->id,
                    'starts_at' => $cycleStart,
                    'ends_at' => $cycleEnd,
                    'prorated_base_price' => $plan->base_price,
                    'prorated_included_units' => $plan->included_usage_units,
                    'overage_rate_per_unit' => $plan->overage_rate_per_unit,
                ]);
                $segments = collect([$segment->load('plan')]);
            }

            $totalBaseAmount = 0.00;
            $totalOverageAmount = 0.00;
            $totalUnitsConsumed = 0;
            $lineItemsData = [];

            foreach ($segments as $index => $segment) {
                $plan = $segment->plan;
                $segStart = Carbon::parse($segment->starts_at);
                $segEnd = Carbon::parse($segment->ends_at);

                // Usage in this segment
                $segUsage = $this->getCustomerUsageBetween($customer->id, $segStart, $segEnd);
                $totalUnitsConsumed += $segUsage;

                // Base fee for this segment
                $baseFee = (float) $segment->prorated_base_price;
                $totalBaseAmount += $baseFee;

                $segDateLabel = $segStart->format('M d').' - '.$segEnd->format('M d');
                $lineItemsData[] = [
                    'description' => "Base Subscription: {$plan->name} ({$segDateLabel})",
                    'type' => 'base_fee',
                    'units' => null,
                    'unit_rate' => null,
                    'amount' => $baseFee,
                ];

                // Overage calculation for this segment
                $overage = $this->calculateOverage(
                    $segUsage,
                    (int) $segment->prorated_included_units,
                    (float) $segment->overage_rate_per_unit
                );

                $totalOverageAmount += $overage['overage_charge'];

                if ($overage['overage_units'] > 0) {
                    $lineItemsData[] = [
                        'description' => "Usage Overage: {$overage['overage_units']} units @ {$segment->overage_rate_per_unit}/unit ({$plan->name})",
                        'type' => 'overage_fee',
                        'units' => $overage['overage_units'],
                        'unit_rate' => $segment->overage_rate_per_unit,
                        'amount' => $overage['overage_charge'],
                    ];
                }
            }

            $totalAmount = round($totalBaseAmount + $totalOverageAmount, 2);

            // Generate unique invoice number: INV-YYYYMM-CUSTID-RAND
            $invoiceNumber = sprintf(
                'INV-%s-%d-%s',
                $cycleEnd->format('Ym'),
                $customer->id,
                strtoupper(Str::random(4))
            );

            $invoice = Invoice::create([
                'customer_id' => $customer->id,
                'subscription_id' => $subscription->id,
                'invoice_number' => $invoiceNumber,
                'cycle_start' => $cycleStart->toDateString(),
                'cycle_end' => $cycleEnd->toDateString(),
                'base_amount' => $totalBaseAmount,
                'overage_amount' => $totalOverageAmount,
                'total_amount' => $totalAmount,
                'total_units_consumed' => $totalUnitsConsumed,
                'status' => 'finalized',
            ]);

            foreach ($lineItemsData as $item) {
                $invoice->lineItems()->create($item);
            }

            return $invoice->load('lineItems');
        });
    }
}
