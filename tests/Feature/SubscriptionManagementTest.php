<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Merchant $merchant;
    protected Plan $growthPlan;
    protected Plan $starterPlan;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create([
            'name' => 'Acme Corp',
            'email' => 'acme@example.com',
            'api_key' => 'sub_test_key_123',
            'currency' => 'INR',
        ]);

        $this->growthPlan = Plan::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Growth',
            'code' => 'growth-monthly',
            'base_price' => 15000.00,
            'billing_cycle' => 'monthly',
            'included_usage_units' => 250000,
            'overage_rate_per_unit' => 0.50,
            'is_active' => true,
        ]);

        $this->starterPlan = Plan::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Starter',
            'code' => 'starter-monthly',
            'base_price' => 5000.00,
            'billing_cycle' => 'monthly',
            'included_usage_units' => 25000,
            'overage_rate_per_unit' => 0.75,
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Omega Logistics',
            'email' => 'omega@example.com',
        ]);
    }

    /**
     * Test Scope 2: Customers subscribe to a merchant's plan.
     */
    public function test_customer_can_subscribe_to_a_merchants_plan(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'plan_id' => $this->growthPlan->id,
            'start_date' => '2026-09-01',
        ];

        $response = $this->postJson('/subscriptions', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data.customer_id', $this->customer->id)
            ->assertJsonPath('data.plan_id', $this->growthPlan->id);

        $this->assertDatabaseHas('subscriptions', [
            'customer_id' => $this->customer->id,
            'plan_id' => $this->growthPlan->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('subscription_segments', [
            'plan_id' => $this->growthPlan->id,
            'prorated_base_price' => 15000.00,
            'prorated_included_units' => 250000,
        ]);
    }

    /**
     * Test tenant isolation: Customer cannot subscribe to another merchant's plan.
     */
    public function test_cannot_subscribe_to_another_merchants_plan(): void
    {
        $otherMerchant = Merchant::create([
            'name' => 'Other Merchant',
            'email' => 'other@example.com',
            'api_key' => 'other_key',
            'currency' => 'USD',
        ]);

        $otherPlan = Plan::create([
            'merchant_id' => $otherMerchant->id,
            'name' => 'Competitor Plan',
            'code' => 'competitor-plan',
            'base_price' => 99.00,
            'billing_cycle' => 'monthly',
            'included_usage_units' => 1000,
            'overage_rate_per_unit' => 0.10,
        ]);

        $response = $this->postJson('/subscriptions', [
            'customer_id' => $this->customer->id,
            'plan_id' => $otherPlan->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan_id']);
    }

    /**
     * Test Requirement 8: Customer upgrading their plan mid-cycle.
     */
    public function test_customer_can_upgrade_plan_mid_cycle(): void
    {
        // 1. Initial subscription starts with Starter plan
        $sub = Subscription::create([
            'customer_id' => $this->customer->id,
            'plan_id' => $this->starterPlan->id,
            'current_cycle_start' => '2026-09-01',
            'current_cycle_end' => '2026-09-30',
            'status' => 'active',
        ]);

        $sub->segments()->create([
            'plan_id' => $this->starterPlan->id,
            'starts_at' => Carbon::parse('2026-09-01')->startOfDay(),
            'ends_at' => Carbon::parse('2026-09-30')->endOfDay(),
            'prorated_base_price' => $this->starterPlan->base_price,
            'prorated_included_units' => $this->starterPlan->included_usage_units,
            'overage_rate_per_unit' => $this->starterPlan->overage_rate_per_unit,
        ]);

        // 2. Customer upgrades to Growth plan mid-cycle on Sept 11
        $response = $this->postJson("/subscriptions/{$sub->id}/change-plan", [
            'plan_id' => $this->growthPlan->id,
            'transition_time' => '2026-09-11 00:00:00',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data.plan_id', $this->growthPlan->id);

        $sub->refresh();
        $this->assertEquals($this->growthPlan->id, $sub->plan_id);
        $this->assertCount(2, $sub->segments);

        // Segment 1 (Starter, 10 days out of 30)
        $seg1 = $sub->segments[0];
        $this->assertEquals($this->starterPlan->id, $seg1->plan_id);
        $this->assertEquals(round(5000 * (10 / 30), 2), $seg1->prorated_base_price);

        // Segment 2 (Growth, 20 days out of 30)
        $seg2 = $sub->segments[1];
        $this->assertEquals($this->growthPlan->id, $seg2->plan_id);
        $this->assertEquals(round(15000 * (20 / 30), 2), $seg2->prorated_base_price);
    }
}
