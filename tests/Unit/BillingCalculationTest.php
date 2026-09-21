<?php

namespace Tests\Unit;

use App\Services\BillingCalculationService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class BillingCalculationTest extends TestCase
{
    protected BillingCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BillingCalculationService();
    }

    /**
     * Test full cycle proration ratio is 1.0 and full base price is returned.
     */
    public function test_full_cycle_proration_ratio(): void
    {
        $cycleStart = '2026-09-01';
        $cycleEnd = '2026-09-30';

        $res = $this->service->calculateProration(
            basePrice: 100.00,
            includedUnits: 1000,
            startDate: $cycleStart,
            endDate: $cycleEnd,
            cycleStart: $cycleStart,
            cycleEnd: $cycleEnd
        );

        $this->assertEquals(1.0, $res['ratio']);
        $this->assertEquals(100.00, $res['prorated_base_price']);
        $this->assertEquals(1000, $res['prorated_units']);
    }

    /**
     * Test mid-cycle subscription start proration.
     * E.g. Starts on day 16 in a 30-day month (half the month active).
     */
    public function test_mid_cycle_start_proration(): void
    {
        $cycleStart = Carbon::parse('2026-09-01')->startOfDay();
        $cycleEnd = Carbon::parse('2026-09-30')->endOfDay();
        $start = Carbon::parse('2026-09-16')->startOfDay(); // 15 days active out of 30

        $res = $this->service->calculateProration(
            basePrice: 300.00,
            includedUnits: 3000,
            startDate: $start,
            endDate: $cycleEnd,
            cycleStart: $cycleStart,
            cycleEnd: $cycleEnd
        );

        // 15 days out of 30 days = 0.5 ratio
        $this->assertEquals(0.5, round($res['ratio'], 2));
        $this->assertEquals(150.00, $res['prorated_base_price']);
        $this->assertEquals(1500, $res['prorated_units']);
    }

    /**
     * Test overage calculation when usage exceeds allowance.
     */
    public function test_overage_calculation_exceeding_allowance(): void
    {
        $res = $this->service->calculateOverage(
            unitsConsumed: 1500,
            includedUnits: 1000,
            overageRate: 0.50
        );

        $this->assertEquals(500, $res['overage_units']);
        $this->assertEquals(250.00, $res['overage_charge']);
    }

    /**
     * Test overage calculation when usage is within allowance (no overage charge).
     */
    public function test_overage_calculation_within_allowance(): void
    {
        $res = $this->service->calculateOverage(
            unitsConsumed: 800,
            includedUnits: 1000,
            overageRate: 0.50
        );

        $this->assertEquals(0, $res['overage_units']);
        $this->assertEquals(0.00, $res['overage_charge']);
    }

    /**
     * Test mid-cycle upgrade/downgrade segment calculations (Requirement 8).
     * 30-day cycle:
     * Segment 1: Plan A (Base $100, 1,000 units, overage $0.50/unit) for 10 days (1/3).
     * Segment 2: Plan B (Base $200, 3,000 units, overage $0.25/unit) for 20 days (2/3).
     */
    public function test_segmented_mid_cycle_upgrade_math(): void
    {
        $cycleStart = Carbon::parse('2026-09-01')->startOfDay();
        $transition = Carbon::parse('2026-09-11')->startOfDay(); // Day 1 to 10 is Plan A (10 days)
        $cycleEnd = Carbon::parse('2026-09-30')->endOfDay();     // Day 11 to 30 is Plan B (20 days)

        // Segment 1 proration (Plan A)
        $seg1 = $this->service->calculateProration(
            basePrice: 90.00,
            includedUnits: 900,
            startDate: $cycleStart,
            endDate: $transition,
            cycleStart: $cycleStart,
            cycleEnd: $cycleEnd
        );

        $this->assertEquals(0.33, round($seg1['ratio'], 2));
        $this->assertEquals(30.00, round($seg1['prorated_base_price'], 2));
        $this->assertEquals(300, $seg1['prorated_units']);

        // Suppose Segment 1 had 500 units consumed -> 200 overage units @ 0.50 = $100
        $overage1 = $this->service->calculateOverage(500, $seg1['prorated_units'], 0.50);
        $this->assertEquals(200, $overage1['overage_units']);
        $this->assertEquals(100.00, $overage1['overage_charge']);

        // Segment 2 proration (Plan B)
        $seg2 = $this->service->calculateProration(
            basePrice: 180.00,
            includedUnits: 3000,
            startDate: $transition,
            endDate: $cycleEnd,
            cycleStart: $cycleStart,
            cycleEnd: $cycleEnd
        );

        $this->assertEquals(0.67, round($seg2['ratio'], 2));
        $this->assertEquals(120.00, round($seg2['prorated_base_price'], 2));
        $this->assertEquals(2000, $seg2['prorated_units']);

        // Suppose Segment 2 had 2,500 units consumed -> 500 overage units @ 0.25 = $125
        $overage2 = $this->service->calculateOverage(2500, $seg2['prorated_units'], 0.25);
        $this->assertEquals(500, $overage2['overage_units']);
        $this->assertEquals(125.00, $overage2['overage_charge']);

        // Combined bill:
        // Base fees: $30 + $120 = $150
        // Overages: $100 + $125 = $225
        // Total = $375
        $totalBase = $seg1['prorated_base_price'] + $seg2['prorated_base_price'];
        $totalOverage = $overage1['overage_charge'] + $overage2['overage_charge'];
        $totalInvoice = $totalBase + $totalOverage;

        $this->assertEquals(150.00, $totalBase);
        $this->assertEquals(225.00, $totalOverage);
        $this->assertEquals(375.00, $totalInvoice);
    }
}
