<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DailyUsageSummary;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Merchant $merchant;
    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create([
            'name' => 'Acme Corp',
            'email' => 'acme@example.com',
            'api_key' => 'dashboard_test_key',
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
    }

    /**
     * Test GET /api/merchants/{id}/dashboard returns valid top 5 and projected revenue.
     */
    public function test_dashboard_api_returns_top_5_and_projected_revenue(): void
    {
        $now = Carbon::now();
        $thisMonth = $now->toDateString();

        // Create 6 customers with varying usage to verify top 5 ordering
        for ($i = 1; $i <= 6; $i++) {
            $customer = Customer::create([
                'merchant_id' => $this->merchant->id,
                'name' => "Customer {$i}",
                'email' => "cust{$i}@example.com",
            ]);

            Subscription::create([
                'customer_id' => $customer->id,
                'plan_id' => $this->plan->id,
                'current_cycle_start' => $now->copy()->startOfMonth()->toDateString(),
                'current_cycle_end' => $now->copy()->endOfMonth()->toDateString(),
                'status' => 'active',
            ]);

            DailyUsageSummary::create([
                'customer_id' => $customer->id,
                'usage_date' => $thisMonth,
                'total_units' => $i * 10000, // 10k, 20k, 30k, 40k, 50k, 60k
            ]);
        }

        $response = $this->getJson("/api/merchants/{$this->merchant->id}/dashboard");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure([
                'status',
                'data' => [
                    'merchant',
                    'active_plan',
                    'current_cycle_usage',
                    'projected_overage_revenue',
                    'top_5_customers',
                    'churn_risk',
                    'daily_usage_trend',
                    'system_status',
                ],
            ]);

        $top5 = $response->json('data.top_5_customers');
        $this->assertCount(5, $top5);
        $this->assertEquals('Customer 6', $top5[0]['name']);
        $this->assertEquals(60000, $top5[0]['usage']);
    }

    /**
     * Test churn risk detection when customer usage drops > 50% month-over-month (Req 5).
     */
    public function test_dashboard_detects_churn_risk_over_50_percent(): void
    {
        $now = Carbon::now();
        $startOfThisMonth = $now->copy()->startOfMonth()->addDays(5)->toDateString();
        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth()->addDays(15)->toDateString();

        $churnCustomer = Customer::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Nova Traders',
            'email' => 'nova@example.com',
        ]);

        Subscription::create([
            'customer_id' => $churnCustomer->id,
            'plan_id' => $this->plan->id,
            'current_cycle_start' => $now->copy()->startOfMonth()->toDateString(),
            'current_cycle_end' => $now->copy()->endOfMonth()->toDateString(),
            'status' => 'active',
        ]);

        // Last month: 10,000 units
        DailyUsageSummary::create([
            'customer_id' => $churnCustomer->id,
            'usage_date' => $startOfLastMonth,
            'total_units' => 10000,
        ]);

        // This month: 3,800 units (62% drop)
        DailyUsageSummary::create([
            'customer_id' => $churnCustomer->id,
            'usage_date' => $startOfThisMonth,
            'total_units' => 3800,
        ]);

        $response = $this->getJson("/api/merchants/{$this->merchant->id}/dashboard");

        $response->assertStatus(200);
        $churnList = $response->json('data.churn_risk');

        $this->assertCount(1, $churnList);
        $this->assertEquals('Nova Traders', $churnList[0]['name']);
        $this->assertEquals(62, $churnList[0]['drop_percentage']);
    }

    /**
     * Test web view renders wireframe HTML successfully.
     */
    public function test_dashboard_web_view_renders(): void
    {
        $response = $this->get("/merchants/{$this->merchant->id}/dashboard");

        $response->assertStatus(200)
            ->assertSee('Merchant Dashboard — Acme Corp')
            ->assertSee('Top 5 Customers by Usage')
            ->assertSee('Current Cycle Usage');
    }
}
