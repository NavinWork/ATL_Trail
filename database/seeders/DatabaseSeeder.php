<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\DailyUsageSummary;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageEvent;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Merchant: Acme Corp
        $merchant = Merchant::create([
            'name' => 'Acme Corp',
            'email' => 'admin@acme-corp.com',
            'api_key' => 'ak_live_acme_'.Str::random(24),
            'currency' => 'INR',
        ]);

        // 2. Create Plans
        $growthPlan = Plan::create([
            'merchant_id' => $merchant->id,
            'name' => 'Growth',
            'code' => 'growth-monthly',
            'base_price' => 15000.00,
            'billing_cycle' => 'monthly',
            'included_usage_units' => 250000,
            'overage_rate_per_unit' => 0.50,
            'is_active' => true,
        ]);

        $starterPlan = Plan::create([
            'merchant_id' => $merchant->id,
            'name' => 'Starter',
            'code' => 'starter-monthly',
            'base_price' => 5000.00,
            'billing_cycle' => 'monthly',
            'included_usage_units' => 25000,
            'overage_rate_per_unit' => 0.75,
            'is_active' => true,
        ]);

        $enterprisePlan = Plan::create([
            'merchant_id' => $merchant->id,
            'name' => 'Enterprise',
            'code' => 'enterprise-monthly',
            'base_price' => 50000.00,
            'billing_cycle' => 'monthly',
            'included_usage_units' => 1000000,
            'overage_rate_per_unit' => 0.30,
            'is_active' => true,
        ]);

        $now = Carbon::now();
        $cycleStart = $now->copy()->startOfMonth()->toDateString();
        $cycleEnd = $now->copy()->endOfMonth()->toDateString();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();

        // 3. Create Key Customers from Wireframe
        $customersData = [
            [
                'name' => 'Beta Retail Pvt Ltd',
                'email' => 'billing@betaretail.com',
                'plan' => $starterPlan, // allowance 25k -> usage 38.2k -> 13.2k overage @ 0.75 = 9,900
                'usage_this_month' => 38200,
                'usage_last_month' => 35000,
            ],
            [
                'name' => 'Craft Foods Co.',
                'email' => 'ops@craftfoods.io',
                'plan' => $starterPlan, // allowance 25k -> usage 31.05k -> 6.05k overage @ 0.75 = 4,537.50
                'usage_this_month' => 31050,
                'usage_last_month' => 28000,
            ],
            [
                'name' => 'Delta Mart',
                'email' => 'finance@deltamart.com',
                'plan' => $starterPlan, // allowance 25k -> usage 27.9k -> 2.9k overage @ 0.75 = 2,175
                'usage_this_month' => 27900,
                'usage_last_month' => 26000,
            ],
            [
                'name' => 'Apex Logistics',
                'email' => 'tech@apexlogistics.com',
                'plan' => $starterPlan, // allowance 25k -> usage 55k -> 30k overage @ 0.75 = 22,500
                'usage_this_month' => 55000,
                'usage_last_month' => 52000,
            ],
            [
                'name' => 'Zenith Retail',
                'email' => 'support@zenithretail.in',
                'plan' => $starterPlan, // allowance 25k -> usage 23.87k -> under allowance
                'usage_this_month' => 23870,
                'usage_last_month' => 22000,
            ],
            // Churn Risk Customers
            [
                'name' => 'Nova Traders',
                'email' => 'contact@novatraders.com',
                'plan' => $starterPlan,
                'usage_this_month' => 3800,
                'usage_last_month' => 10000, // 62% drop!
            ],
            [
                'name' => 'QuickMart',
                'email' => 'admin@quickmart.com',
                'plan' => $starterPlan,
                'usage_this_month' => 4500,
                'usage_last_month' => 10000, // 55% drop!
            ],
        ];

        // Total usage = 38200 + 31050 + 27900 + 55000 + 23870 + 3800 + 4500 = 184,320 units! (Exact wireframe match!)
        // Projected overage = (13,200 * 0.75) + (6,050 * 0.75) + (2,900 * 0.75) + (34,650 * 0.75) = 9,900 + 4,537.50 + 2,175 + 25,987.50 = ₹42,600! (Exact wireframe match!)

        foreach ($customersData as $cData) {
            $customer = Customer::create([
                'merchant_id' => $merchant->id,
                'name' => $cData['name'],
                'email' => $cData['email'],
                'external_id' => 'cust_'.Str::random(10),
            ]);

            $sub = Subscription::create([
                'customer_id' => $customer->id,
                'plan_id' => $cData['plan']->id,
                'current_cycle_start' => $cycleStart,
                'current_cycle_end' => $cycleEnd,
                'status' => 'active',
            ]);

            // Initial segment
            $sub->segments()->create([
                'plan_id' => $cData['plan']->id,
                'starts_at' => Carbon::parse($cycleStart)->startOfDay(),
                'ends_at' => Carbon::parse($cycleEnd)->endOfDay(),
                'prorated_base_price' => $cData['plan']->base_price,
                'prorated_included_units' => $cData['plan']->included_usage_units,
                'overage_rate_per_unit' => $cData['plan']->overage_rate_per_unit,
            ]);

            // Seed last month summary for MoM comparison
            DailyUsageSummary::create([
                'customer_id' => $customer->id,
                'usage_date' => $lastMonthStart->copy()->addDays(15)->toDateString(),
                'total_units' => $cData['usage_last_month'],
            ]);

            // Distribute this month's usage across days 1..today
            $remaining = $cData['usage_this_month'];
            $daysToSpread = min(20, max(5, $now->day));
            $basePerDay = (int) floor($remaining / $daysToSpread);

            for ($d = 1; $d <= $daysToSpread; $d++) {
                $unitsForDay = ($d === $daysToSpread) ? $remaining : $basePerDay;
                $remaining -= $unitsForDay;
                $usageDate = $now->copy()->startOfMonth()->addDays($d - 1)->toDateString();

                DailyUsageSummary::create([
                    'customer_id' => $customer->id,
                    'usage_date' => $usageDate,
                    'total_units' => $unitsForDay,
                ]);

                // Create a few sample raw events with idempotency keys
                UsageEvent::create([
                    'customer_id' => $customer->id,
                    'units' => $unitsForDay,
                    'usage_date' => Carbon::parse($usageDate)->setTime(rand(9, 18), rand(0, 59)),
                    'idempotency_key' => 'seed_evt_'.$customer->id.'_'.$d.'_'.Str::random(8),
                    'processed_at' => now(),
                ]);
            }
        }
    }
}
