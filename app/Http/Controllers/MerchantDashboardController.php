<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\DailyUsageSummary;
use App\Models\Merchant;
use App\Services\PlanCacheService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MerchantDashboardController extends Controller
{
    public function __construct(protected PlanCacheService $planCacheService)
    {
    }

    /**
     * Get aggregated dashboard data array.
     */
    public function getDashboardData(int $merchantId): array
    {
        $merchant = Merchant::with('plans')->findOrFail($merchantId);
        $activePlan = $merchant->plans()->where('is_active', true)->first();

        $now = Carbon::now();
        $startOfThisMonth = $now->copy()->startOfMonth()->toDateString();
        $endOfThisMonth = $now->copy()->endOfMonth()->toDateString();
        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth()->toDateString();
        $endOfLastMonth = $now->copy()->subMonth()->endOfMonth()->toDateString();

        $customers = Customer::where('merchant_id', $merchantId)
            ->with(['activeSubscription.plan'])
            ->get();

        $customerIds = $customers->pluck('id')->toArray();

        // 1. Current Month Usage per Customer
        $thisMonthSummaries = DailyUsageSummary::whereIn('customer_id', $customerIds)
            ->whereBetween('usage_date', [$startOfThisMonth, $endOfThisMonth])
            ->selectRaw('customer_id, SUM(total_units) as total_units')
            ->groupBy('customer_id')
            ->pluck('total_units', 'customer_id')
            ->toArray();

        // 2. Last Month Usage per Customer (for MoM Churn Calculation)
        $lastMonthSummaries = DailyUsageSummary::whereIn('customer_id', $customerIds)
            ->whereBetween('usage_date', [$startOfLastMonth, $endOfLastMonth])
            ->selectRaw('customer_id, SUM(total_units) as total_units')
            ->groupBy('customer_id')
            ->pluck('total_units', 'customer_id')
            ->toArray();

        $totalCycleUsage = 0;
        $totalPlanAllowance = 0;
        $projectedOverageRevenue = 0.0;
        $customerUsageList = [];
        $churnRiskCustomers = [];

        foreach ($customers as $customer) {
            $usageThisMonth = (int) ($thisMonthSummaries[$customer->id] ?? 0);
            $usageLastMonth = (int) ($lastMonthSummaries[$customer->id] ?? 0);
            $totalCycleUsage += $usageThisMonth;

            $sub = $customer->activeSubscription;
            $plan = $sub?->plan ?? $activePlan;
            $allowance = $plan ? (int) $plan->included_usage_units : 250000;
            $overageRate = $plan ? (float) $plan->overage_rate_per_unit : 0.50;

            $totalPlanAllowance += $allowance;

            // Projected overage calculation for this customer
            if ($usageThisMonth > $allowance) {
                $overageUnits = $usageThisMonth - $allowance;
                $projectedOverageRevenue += ($overageUnits * $overageRate);
            }

            $percentageOfAllowance = $allowance > 0 ? round(($usageThisMonth / $allowance) * 100) : 0;

            $customerUsageList[] = [
                'id' => $customer->id,
                'name' => $customer->name,
                'usage' => $usageThisMonth,
                'allowance' => $allowance,
                'percentage_of_allowance' => $percentageOfAllowance,
            ];

            // Churn Risk Detection: Usage dropped > 50% month-over-month (Req 5)
            if ($usageLastMonth > 1000) { // Baseline threshold
                $drop = (($usageLastMonth - $usageThisMonth) / $usageLastMonth) * 100;
                if ($drop > 50) {
                    $churnRiskCustomers[] = [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'last_month_usage' => $usageLastMonth,
                        'this_month_usage' => $usageThisMonth,
                        'drop_percentage' => round($drop),
                    ];
                }
            }
        }

        // Top 5 Customers by Usage this month
        usort($customerUsageList, fn($a, $b) => $b['usage'] <=> $a['usage']);
        $top5Customers = array_slice($customerUsageList, 0, 5);

        // Daily Usage Trend (last 30 days)
        $trendStartDate = $now->copy()->subDays(29)->toDateString();
        $dailyTrendsRaw = DailyUsageSummary::whereIn('customer_id', $customerIds)
            ->whereBetween('usage_date', [$trendStartDate, $now->toDateString()])
            ->selectRaw('DATE(usage_date) as u_date, SUM(total_units) as total_units')
            ->groupBy('u_date')
            ->pluck('total_units', 'u_date')
            ->toArray();

        $dailyUsageTrend = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = $now->copy()->subDays($i)->toDateString();
            $dailyUsageTrend[] = [
                'date' => $d,
                'formatted_date' => Carbon::parse($d)->format('M d'),
                'units' => (int) ($dailyTrendsRaw[$d] ?? 0),
            ];
        }

        return [
            'merchant' => [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'currency' => $merchant->currency ?? 'INR',
            ],
            'active_plan' => [
                'name' => $activePlan ? $activePlan->name : 'Growth',
                'billing_cycle' => $activePlan ? $activePlan->billing_cycle : 'monthly',
                'included_units' => $activePlan ? $activePlan->included_usage_units : 250000,
            ],
            'current_cycle_usage' => [
                'total_units' => $totalCycleUsage,
                'total_allowance' => $activePlan ? $activePlan->included_usage_units : 250000,
                'percentage' => $activePlan && $activePlan->included_usage_units > 0
                    ? round(($totalCycleUsage / $activePlan->included_usage_units) * 100, 1)
                    : 0,
            ],
            'projected_overage_revenue' => round($projectedOverageRevenue, 2),
            'top_5_customers' => $top5Customers,
            'churn_risk' => $churnRiskCustomers,
            'daily_usage_trend' => $dailyUsageTrend,
            'system_status' => [
                'cache_driver' => config('cache.default'),
                'plan_cache_ttl' => '10m',
                'nightly_job' => 'queued, chunked (5k rows/batch)',
                'usage_rate_limit' => '120 req/min per API key',
            ],
        ];
    }

    /**
     * API endpoint returning dashboard JSON.
     */
    public function show(int $id): JsonResponse
    {
        $data = $this->getDashboardData($id);

        return response()->json([
            'status' => 'ok',
            'data' => $data,
        ]);
    }

    /**
     * Web view rendering the wireframe dashboard.
     */
    public function view(Request $request, int $id): View|JsonResponse
    {
        $data = $this->getDashboardData($id);

        if ($request->wantsJson()) {
            return response()->json(['status' => 'ok', 'data' => $data]);
        }

        return view('dashboard', ['data' => $data]);
    }
}
