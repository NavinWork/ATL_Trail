<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DailyUsageSummary;
use App\Models\Merchant;
use App\Models\UsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected Merchant $merchant;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create([
            'name' => 'Acme Corp',
            'email' => 'acme@example.com',
            'api_key' => 'test_api_key_12345',
            'currency' => 'INR',
        ]);

        $this->customer = Customer::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Beta Retail Pvt Ltd',
            'email' => 'beta@example.com',
        ]);
    }

    /**
     * Test successful usage event ingestion returns 201 Created.
     */
    public function test_it_successfully_records_usage_event(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'units' => 150,
            'usage_date' => '2026-09-21 14:30:00',
            'idempotency_key' => 'evt_test_unique_001',
        ];

        $response = $this->postJson('/usage', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'ok',
                'idempotent' => false,
            ]);

        $this->assertDatabaseHas('usage_events', [
            'customer_id' => $this->customer->id,
            'units' => 150,
            'idempotency_key' => 'evt_test_unique_001',
        ]);

        $this->assertDatabaseHas('daily_usage_summaries', [
            'customer_id' => $this->customer->id,
            'usage_date' => '2026-09-21',
            'total_units' => 150,
        ]);
    }

    /**
     * Test idempotency: retried request must return 200 and NOT double count units.
     */
    public function test_idempotency_prevents_double_counting_on_replay(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'units' => 500,
            'usage_date' => '2026-09-21 15:00:00',
            'idempotency_key' => 'evt_idempotent_test_key_999',
        ];

        // 1st request -> 201 Created
        $response1 = $this->postJson('/usage', $payload);
        $response1->assertStatus(201)
            ->assertJson(['idempotent' => false]);

        $this->assertEquals(1, UsageEvent::where('idempotency_key', 'evt_idempotent_test_key_999')->count());
        $this->assertEquals(500, DailyUsageSummary::where('customer_id', $this->customer->id)->value('total_units'));

        // 2nd request (replay with same key) -> 200 OK, idempotent true
        $response2 = $this->postJson('/usage', $payload);
        $response2->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'idempotent' => true,
            ]);

        // Verify count is still exactly 1 and units were NOT double-counted
        $this->assertEquals(1, UsageEvent::where('idempotency_key', 'evt_idempotent_test_key_999')->count());
        $this->assertEquals(500, DailyUsageSummary::where('customer_id', $this->customer->id)->value('total_units'));
    }

    /**
     * Test validation failure on missing or invalid fields.
     */
    public function test_validation_errors_for_invalid_payload(): void
    {
        $invalidPayload = [
            'customer_id' => 999999, // non-existent customer
            'units' => 0, // invalid: min is 1
            'usage_date' => 'not-a-date',
            // missing idempotency_key
        ];

        $response = $this->postJson('/usage', $invalidPayload);

        $response->assertStatus(422)
            ->assertJsonStructure(['status', 'message', 'errors']);
    }

    /**
     * Test rate limiting on /usage endpoint.
     */
    public function test_rate_limiting_triggers_after_exceeding_threshold(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'units' => 1,
            'usage_date' => '2026-09-21 10:00:00',
        ];

        // Send 120 requests
        for ($i = 0; $i < 120; $i++) {
            $payload['idempotency_key'] = "rate_limit_test_{$i}";
            $res = $this->withHeaders(['X-API-KEY' => 'test_client_key_1'])
                ->postJson('/usage', $payload);
            $this->assertTrue(in_array($res->status(), [200, 201]));
        }

        // 121st request should be rate limited (HTTP 429)
        $payload['idempotency_key'] = "rate_limit_test_121";
        $rateLimitedResponse = $this->withHeaders(['X-API-KEY' => 'test_client_key_1'])
            ->postJson('/usage', $payload);

        $rateLimitedResponse->assertStatus(429);
    }
}
