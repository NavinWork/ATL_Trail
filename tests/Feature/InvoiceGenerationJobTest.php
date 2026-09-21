<?php

namespace Tests\Feature;

use App\Jobs\AggregateDailyUsageJob;
use App\Jobs\GenerateCycleInvoicesJob;
use App\Models\Customer;
use App\Models\DailyUsageSummary;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageEvent;
use App\Services\BillingCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceGenerationJobTest extends TestCase
{
    use RefreshDatabase;

    protected Merchant $merchant;
    protected Plan $plan;
    protected Customer $customer;
    protected Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create([
            'name' => 'Acme Corp',
            'email' => 'billing@acme.com',
            'api_key' => 'acme_key_invoice_test',
            'currency' => 'INR',
        ]);

        $this->plan = Plan::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Starter',
            'code' => 'starter-monthly',
            'base_price' => 1000.00,
            'billing_cycle' => 'monthly',
            'included_usage_units' => 10000,
            'overage_rate_per_unit' => 0.50,
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Beta Retail Pvt Ltd',
            'email' => 'beta@example.com',
        ]);

        $this->subscription = Subscription::create([
            'customer_id' => $this->customer->id,
            'plan_id' => $this->plan->id,
            'current_cycle_start' => '2026-08-01',
            'current_cycle_end' => '2026-08-31',
            'status' => 'active',
        ]);
    }

    /**
     * Test AggregateDailyUsageJob processes raw events into daily summaries in chunks.
     */
    public function test_aggregate_daily_usage_job_chunks_and_aggregates(): void
    {
        // Insert 10 unaggregated usage events
        for ($i = 1; $i <= 10; $i++) {
            UsageEvent::create([
                'customer_id' => $this->customer->id,
                'units' => 100,
                'usage_date' => '2026-08-15 10:00:00',
                'idempotency_key' => "chunk_test_{$i}",
                'processed_at' => null,
            ]);
        }

        $this->assertEquals(10, UsageEvent::whereNull('processed_at')->count());

        // Run job with chunk size 3
        (new AggregateDailyUsageJob(chunkSize: 3))->handle();

        $this->assertEquals(0, UsageEvent::whereNull('processed_at')->count());
        $this->assertEquals(1000, DailyUsageSummary::where('customer_id', $this->customer->id)
            ->where('usage_date', '2026-08-15')
            ->value('total_units'));
    }

    /**
     * Test GenerateCycleInvoicesJob generates invoices with correct base + overage math.
     */
    public function test_generate_cycle_invoices_job_creates_invoice_with_overage(): void
    {
        // Record 15,000 units during August (allowance is 10,000 -> 5,000 overage @ 0.50 = 2,500)
        DailyUsageSummary::create([
            'customer_id' => $this->customer->id,
            'usage_date' => '2026-08-10',
            'total_units' => 15000,
        ]);

        $job = new GenerateCycleInvoicesJob(targetDate: '2026-09-01');
        $job->handle(app(BillingCalculationService::class));

        $invoice = Invoice::where('customer_id', $this->customer->id)->first();
        $this->assertNotNull($invoice);

        $this->assertEquals(1000.00, $invoice->base_amount);
        $this->assertEquals(2500.00, $invoice->overage_amount);
        $this->assertEquals(3500.00, $invoice->total_amount);
        $this->assertEquals(15000, $invoice->total_units_consumed);

        // Check itemized line items
        $this->assertCount(2, $invoice->lineItems);
        $this->assertDatabaseHas('invoice_line_items', [
            'invoice_id' => $invoice->id,
            'type' => 'base_fee',
            'amount' => 1000.00,
        ]);
        $this->assertDatabaseHas('invoice_line_items', [
            'invoice_id' => $invoice->id,
            'type' => 'overage_fee',
            'units' => 5000,
            'amount' => 2500.00,
        ]);
    }

    /**
     * Test mid-cycle upgrade segment billing produces prorated segmented invoice (Requirement 8).
     */
    public function test_mid_cycle_upgrade_invoice_generation(): void
    {
        // Create second plan: Enterprise ($3,000/mo, 30,000 units, overage $0.20/unit)
        $enterprisePlan = Plan::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Enterprise',
            'code' => 'enterprise-monthly',
            'base_price' => 3000.00,
            'billing_cycle' => 'monthly',
            'included_usage_units' => 30000,
            'overage_rate_per_unit' => 0.20,
            'is_active' => true,
        ]);

        // Subscription starts with Plan 1 (Starter) for August (31 days)
        // Customer upgrades mid-cycle on August 16
        $this->subscription->segments()->create([
            'plan_id' => $this->plan->id,
            'starts_at' => '2026-08-01 00:00:00',
            'ends_at' => '2026-08-16 00:00:00',
            'prorated_base_price' => 500.00,
            'prorated_included_units' => 5000,
            'overage_rate_per_unit' => 0.50,
        ]);

        $this->subscription->segments()->create([
            'plan_id' => $enterprisePlan->id,
            'starts_at' => '2026-08-16 00:00:00',
            'ends_at' => '2026-08-31 23:59:59',
            'prorated_base_price' => 1500.00,
            'prorated_included_units' => 15000,
            'overage_rate_per_unit' => 0.20,
        ]);

        // Record usage for Segment 1: 6,000 units (1,000 overage @ 0.50 = 500.00)
        UsageEvent::create([
            'customer_id' => $this->customer->id,
            'units' => 6000,
            'usage_date' => '2026-08-05 12:00:00',
            'idempotency_key' => 'seg1_evt',
            'processed_at' => now(),
        ]);

        // Record usage for Segment 2: 17,000 units (2,000 overage @ 0.20 = 400.00)
        UsageEvent::create([
            'customer_id' => $this->customer->id,
            'units' => 17000,
            'usage_date' => '2026-08-20 12:00:00',
            'idempotency_key' => 'seg2_evt',
            'processed_at' => now(),
        ]);

        $job = new GenerateCycleInvoicesJob(targetDate: '2026-09-01');
        $job->handle(app(BillingCalculationService::class));

        $invoice = Invoice::where('customer_id', $this->customer->id)->first();
        $this->assertNotNull($invoice);

        // Segment 1 base: $500, overage: $500
        // Segment 2 base: $1500, overage: $400
        // Total base: $2000, Total overage: $900 -> Total Invoice = $2900
        $this->assertEquals(2000.00, $invoice->base_amount);
        $this->assertEquals(900.00, $invoice->overage_amount);
        $this->assertEquals(2900.00, $invoice->total_amount);
        $this->assertEquals(23000, $invoice->total_units_consumed);
    }
}
