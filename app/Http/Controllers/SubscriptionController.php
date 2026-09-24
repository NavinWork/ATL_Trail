<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePlanRequest;
use App\Http\Requests\CreateSubscriptionRequest;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\BillingCalculationService;
use App\Services\PlanCacheService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    public function __construct(
        protected PlanCacheService $planCacheService,
        protected BillingCalculationService $billingService
    ) {}

    /**
     * Scope 2: Customers subscribe to a merchant's plan.
     */
    public function store(CreateSubscriptionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $customer = Customer::findOrFail($validated['customer_id']);
        $plan = $this->planCacheService->getPlan($validated['plan_id']) ?? Plan::findOrFail($validated['plan_id']);

        // Check for existing active subscription
        $existing = Subscription::where('customer_id', $customer->id)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer already has an active subscription. Use the plan change/upgrade endpoint to switch plans.',
                'subscription_id' => $existing->id,
            ], 409);
        }

        $startDate = isset($validated['start_date']) ? Carbon::parse($validated['start_date']) : Carbon::now();
        $cycleStart = $startDate->copy()->startOfMonth();
        $cycleEnd = $startDate->copy()->endOfMonth();

        $subscription = DB::transaction(function () use ($customer, $plan, $startDate, $cycleStart, $cycleEnd) {
            $sub = Subscription::create([
                'customer_id' => $customer->id,
                'plan_id' => $plan->id,
                'current_cycle_start' => $cycleStart->toDateString(),
                'current_cycle_end' => $cycleEnd->toDateString(),
                'status' => 'active',
            ]);

            // Calculate proration if subscription starts mid-cycle
            $proration = $this->billingService->calculateProration(
                basePrice: (float) $plan->base_price,
                includedUnits: (int) $plan->included_usage_units,
                startDate: $startDate,
                endDate: $cycleEnd,
                cycleStart: $cycleStart,
                cycleEnd: $cycleEnd
            );

            $sub->segments()->create([
                'plan_id' => $plan->id,
                'starts_at' => $startDate,
                'ends_at' => $cycleEnd,
                'prorated_base_price' => $proration['prorated_base_price'],
                'prorated_included_units' => $proration['prorated_units'],
                'overage_rate_per_unit' => $plan->overage_rate_per_unit,
            ]);

            return $sub;
        });

        return response()->json([
            'status' => 'ok',
            'message' => 'Customer successfully subscribed to plan.',
            'data' => $subscription->load(['plan', 'segments']),
        ], 201);
    }

    /**
     * Requirement 8: Customer upgrading or downgrading their plan mid-cycle.
     */
    public function changePlan(ChangePlanRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();
        $subscription = Subscription::with(['customer', 'plan'])->findOrFail($id);

        $newPlan = $this->planCacheService->getPlan($validated['plan_id']) ?? Plan::findOrFail($validated['plan_id']);

        // Multi-tenant check
        if ($subscription->customer->merchant_id !== $newPlan->merchant_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'New plan does not belong to the customer\'s merchant.',
            ], 422);
        }

        $transitionTime = isset($validated['transition_time'])
            ? Carbon::parse($validated['transition_time'])
            : Carbon::now();

        $subscription->transitionPlan($newPlan, $transitionTime);

        return response()->json([
            'status' => 'ok',
            'message' => 'Subscription plan transitioned successfully with segmented proration.',
            'data' => $subscription->fresh(['plan', 'segments.plan']),
        ]);
    }

    /**
     * Get subscription details with active segments.
     */
    public function show(int $id): JsonResponse
    {
        $subscription = Subscription::with(['customer', 'plan', 'segments.plan'])->findOrFail($id);

        return response()->json([
            'status' => 'ok',
            'data' => $subscription,
        ]);
    }
}
