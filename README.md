# Subscription Billing & Usage-Metering System (Laravel 12)
**Associate Team Lead — Technical Assessment Mini Task**

An enterprise-grade, multi-tenant SaaS subscription billing and high-throughput usage metering platform built with **Laravel 12**, **PHP 8.2**, and modern SaaS engineering patterns.

---

## Table of Contents
1. [Architecture Summary](#1-architecture-summary)
2. [50L+ (5M+) Row Scaling & Partitioning Strategy](#2-50l-5m-row-scaling--partitioning-strategy)
3. [Idempotency & High-Throughput Ingestion](#3-idempotency--high-throughput-ingestion)
4. [Mid-Cycle Upgrade/Downgrade Math & Proration (Req 8)](#4-mid-cycle-upgradedowngrade-math--proration-req-8)
5. [Plan Pricing Cache & Invalidation Strategy (Req 4)](#5-plan-pricing-cache--invalidation-strategy-req-4)
6. [Merchant Dashboard & Churn Risk Engine (Req 5)](#6-merchant-dashboard--churn-risk-engine-req-5)
7. [Rollout & Monitoring Note (Req 10)](#7-rollout--monitoring-note-req-10)
8. [Code Review Exercise (Req 9)](#8-code-review-exercise-req-9)
9. ["Handing This Off" (Handoff Notes & Known Cut Corners)](#9-handing-this-off)
10. [Setup & Run Instructions](#10-setup--run-instructions)
11. [Automated Test Suite](#11-automated-test-suite)

---

## 1. Architecture Summary

The application is structured around clean domain separation, multi-tenant isolation, and high-volume write safety:

```
                  ┌─────────────────────────────────────────┐
                  │          External Clients / SDK         │
                  └────────────────────┬────────────────────┘
                                       │ (120 req/min Rate Limit)
                                       ▼
                  ┌─────────────────────────────────────────┐
                  │    POST /usage (UsageController)        │
                  │   - FormRequest Validation              │
                  │   - Idempotency Key Deduplication       │
                  └────────────┬──────────────────┬─────────┘
                               │                  │
                (Raw Event)    ▼                  ▼  (Pre-Aggregation)
┌──────────────────────────────────────┐  ┌─────────────────────────────────────┐
│  usage_events Table                  │  │  daily_usage_summaries Table        │
│  - 50L+ High Volume                  │  │  - Collapsed 1 row/customer/day     │
│  - Unique (idempotency_key)          │  │  - Atomic Increment via Lock        │
│  - Composite Index (cust_id, date)   │  │  - Rapid O(1) Dashboard & Billing   │
└──────────────────┬───────────────────┘  └──────────────────┬──────────────────┘
                   │                                         │
                   │ (Queued Chunked Batch: 5k rows)         │
                   ▼                                         │
┌──────────────────────────────────────┐                     │
│  AggregateDailyUsageJob              │                     │
└──────────────────────────────────────┘                     │
                                                             ▼
                                          ┌─────────────────────────────────────┐
                                          │   GenerateCycleInvoicesJob          │
                                          │   - Evaluates SubscriptionSegments  │
                                          │   - Calculates Prorated Base Fees   │
                                          │   - Applies Segmented Overage Rates │
                                          └──────────────────┬──────────────────┘
                                                             ▼
                                          ┌─────────────────────────────────────┐
                                          │  invoices & invoice_line_items      │
                                          └─────────────────────────────────────┘
```

### Core Domain Entities
- **`Merchant`**: Multi-tenant root (tenant boundary). Owns Plans and Customers.
- **`Plan`**: Defines base subscription fee, billing cycle (monthly/yearly), included usage allowance, and tiered overage rates per unit.
- **`Customer`**: Subscribed client belonging to a merchant.
- **`Subscription`**: Active customer subscription with cycle start/end dates.
- **`SubscriptionSegment`**: **(Architectural Highlight for Requirement 8)** Tracks mid-cycle plan changes. Each segment stores `starts_at`, `ends_at`, `prorated_base_price`, and `prorated_included_units` to guarantee deterministic billing when plans change mid-month.
- **`UsageEvent`**: High-volume append-only transaction ledger storing raw metering events. Protected by unique `idempotency_key`.
- **`DailyUsageSummary`**: Materialized daily rollup (`customer_id`, `usage_date`, `total_units`). Decouples analytics and invoice generation from scanning 50L+ raw rows.
- **`Invoice` & `InvoiceLineItem`**: Itemized billing statements generated at cycle end.

---

## 2. 50L+ (5M+) Row Scaling & Partitioning Strategy

In a production environment where high-frequency API events generate **50L+ (5,000,000+) rows monthly**, standard monolithic tables cause index bloat, buffer pool thrashing, and table locks. 

### Strategy Implemented:
1. **Materialized Daily Rollup (`daily_usage_summaries`)**:
   - Rather than querying 5,000,000 raw events during invoice generation or dashboard loading, queries target the daily summary table.
   - For 1,000 customers over a 30-day month, the scan space is reduced from **5,000,000 rows to 30,000 rows** (a **99.4% reduction** in read I/O).

2. **Composite Narrow Indexing**:
   - `usage_events` table contains:
     - `UNIQUE INDEX uk_idempotency (idempotency_key)`: B-Tree index for O(1) duplicate checks.
     - `INDEX idx_cust_date (customer_id, usage_date)`: Covered index for fast chronological slice scans.
     - `INDEX idx_processed (processed_at, id)`: Enables chunked worker sweeps via `chunkById()`.

3. **Database Range Partitioning (PostgreSQL / MySQL 8)**:
   - Partition `usage_events` by **monthly range** on `usage_date`:
     ```sql
     ALTER TABLE usage_events PARTITION BY RANGE (TO_DAYS(usage_date)) (
         PARTITION p2026_08 VALUES LESS THAN (TO_DAYS('2026-09-01')),
         PARTITION p2026_09 VALUES LESS THAN (TO_DAYS('2026-10-01')),
         PARTITION p2026_10 VALUES LESS THAN (TO_DAYS('2026-11-01'))
     );
     ```
   - **Pruning**: Queries filtering by current cycle automatically prune 11 out of 12 monthly partitions.
   - **Zero-Downtime Archival**: Once a billing cycle's invoices are audited and locked, older partitions can be detached (`ALTER TABLE usage_events DROP/DETACH PARTITION p2026_08`) and archived to Amazon S3 (Apache Parquet format) or ClickHouse without running expensive `DELETE` queries that lock tables and fragment disk.

4. **Batch Buffer Layer (Next Tier Scale >10k writes/sec)**:
   - For hyperscale bursts, decouple direct HTTP writes by pushing events into a **Redis Stream** or **Apache Kafka** topic, consumed by a background worker batch-inserting with `INSERT INTO usage_events (...) VALUES (...) ON CONFLICT DO NOTHING`.

---

## 3. Idempotency & High-Throughput Ingestion

Client network retries must **never** double-bill customers.

### Implementation:
- **`POST /usage`** requires an `idempotency_key` (UUID or client hash).
- **Two-Tier Deduplication**:
  1. **Fast-path Lookup**: Checks for existing `UsageEvent::where('idempotency_key', $key)->first()`. If found, returns `HTTP 200 OK` with `'idempotent' => true` without altering state.
  2. **Atomic Insert & Concurrency Protection**: If two identical requests hit parallel web workers simultaneously, the database engine enforces the unique index constraint (`uk_idempotency`). The second request catches `QueryException` (code `23000`), gracefully returns `HTTP 200 OK` with existing event metadata, preventing race-condition double counting.
- **Rate Limiting**: Enforced at 120 requests/minute per API key (`X-API-KEY`) via Laravel's cache-backed `RateLimiter`.

---

## 4. Mid-Cycle Upgrade/Downgrade Math & Proration (Req 8)

When a customer upgrades from **Plan A** to **Plan B** on Day 11 of a 30-day month:
- **Segment 1 (Days 1–10)**: 10 days active (ratio: `10/30 = 0.333333`). Base price is prorated to `33.33%`, and included units allowance is prorated to `33.33%`. Usage logged before Day 11 is evaluated against Segment 1's allowance and overage rate.
- **Segment 2 (Days 11–30)**: 20 days active (ratio: `20/30 = 0.666667`). Base price is prorated to `66.67%`, and included units allowance is prorated to `66.67%`. Usage logged after Day 11 is evaluated against Segment 2's allowance and new overage rate.
- **Unified Mathematical Formula**:
  $$\text{Ratio} = \frac{\text{Active Days in Segment}}{\text{Total Days in Billing Cycle}}$$
  $$\text{Segment Base Fee} = \text{Plan Base Price} \times \text{Ratio}$$
  $$\text{Segment Allowance} = \lfloor \text{Plan Included Units} \times \text{Ratio} \rceil$$
  $$\text{Segment Overage} = \max(0, \text{Segment Usage} - \text{Segment Allowance}) \times \text{Segment Overage Rate}$$
- Invoices clearly display itemized line items for each plan segment, base charge, and overage breakdown.

---

## 5. Plan Pricing Cache & Invalidation Strategy (Req 4)

- **Caching Layer**: `PlanCacheService` caches plan configurations and active merchant lists in Redis / cache store with a **10-minute TTL** (`TTL = 600s`), matching wireframe specifications.
- **Cache Keys**:
  - `plan:pricing:{plan_id}`
  - `merchant:{merchant_id}:active_plans`
- **Invalidation Strategy**:
  - **Event-Driven Automatic Invalidation**: Eloquent model lifecycle hooks (`Plan::saved`, `Plan::deleted`) automatically fire cache eviction via `PlanCacheService::invalidatePlan($id)`.
  - **Graceful Fallback**: If cache store is unavailable, service transparently falls back to fresh database queries.
  - **Zero-Stale Window**: Pricing modifications take effect immediately upon merchant save.

---

## 6. Merchant Dashboard & Churn Risk Engine (Req 5)

Available via JSON API (`GET /api/merchants/{id}/dashboard`) and interactive Blade interface (`GET /merchants/{id}/dashboard`):

### Metrics Displayed (Matching Wireframe Reference):
1. **Current Cycle Usage**: Aggregates all active customer usage vs total included allowance (e.g., `184,320 / 250,000 units` - 73.7%).
2. **Projected Overage Revenue**: Calculates customer-by-customer projected overages at cycle end (e.g., `₹42,600`).
3. **Active Plan**: Displays current merchant subscription tier (`Growth — monthly`).
4. **Top 5 Customers by Usage (this cycle)**: Ranked descending by usage units, displaying percentage of included allowance used.
5. **⚠️ Churn Risk Detection**:
   - Compares customer usage in the current cycle against the previous month.
   - Flags accounts experiencing **>50% month-over-month usage drops** (e.g. `Nova Traders — 62% drop`, `QuickMart — 55% drop`).
6. **30-Day Daily Usage Trend**: Interactive SVG sparkline plotting units per day.
7. **System Status**: Displays Redis cache status, batch chunk configuration, and endpoint rate limits.
8. **Live Interactive Event Simulator**: Form on dashboard allowing live submission of `POST /usage` requests to demonstrate idempotency and real-time rollup updates.

---

## 7. Rollout & Monitoring Note (Req 10)

### Minimal-Risk Rollout Strategy:
1. **Zero-Lock Database Migrations**: Apply indexes concurrently (`ALGORITHM=INPLACE, LOCK=NONE` in MySQL, `CREATE INDEX CONCURRENTLY` in Postgres) to ensure table writes are never blocked.
2. **Dark Launch / Shadow Traffic**: Route 100% of production usage write traffic to both the legacy system and the new `POST /usage` endpoint via an API Gateway shadow mirror. Verify idempotency and data parity over 72 hours with zero customer impact.
3. **Canary Invoicing**: Enable the new invoice generator job on an internal test merchant before flipping feature flags for external tenants.

### The "One Thing" On-Call Alert:
> **Primary Alert: Drop in Usage Ingestion Rate vs 7-Day Rolling Baseline (or DLQ Consumer Lag Spike)**  
> If client apps or webhooks silently fail to report API usage, HTTP errors might stay 0 while customers get under-billed or free service. An anomaly alert triggering when **recorded event throughput drops >30% below expected rolling historical volume for >15 minutes** immediately catches silent pipeline disconnections before month-end invoicing.

---

## 8. Code Review Exercise (Req 9)

A comprehensive code review on the mid-level engineer's PR has been documented in [`CODE_REVIEW.md`](file:///Users/navin/Documents/Projects/Trail_2/CODE_REVIEW.md).

**Key Flaws Identified & Addressed**:
1. Fatal null pointer crash on invalid `customer_id`.
2. Total absence of request validation (accepts negative units, invalid dates).
3. Critical multi-tenant isolation failure (no merchant authorization check).
4. No idempotency mechanism (duplicate requests result in double billing).
5. Synchronous database bottlenecking at high throughput.
6. Non-standard REST HTTP response codes.

---

## 9. "Handing This Off"

### 2–3 Flags for the Mid-Level Engineer Picking This Up:
1. **High-Throughput Buffer Queue**: If ingestion scales above 5,000 writes/second, migrate `UsageController::store` to dispatch directly to a Redis Stream or Kafka cluster instead of synchronous SQLite/MySQL inserts.
2. **Automated Partition Rotation**: Set up a scheduled monthly command (`php artisan partitions:create-next`) to pre-create upcoming monthly tables in PostgreSQL/MySQL before the 1st of every month.
3. **Multi-Currency & Timezones**: Currently, calculations run on the merchant's base currency (`INR`/`USD`) and UTC/merchant local midnight. Ensure customer-specific timezones are respected when expanding to global tenants.

### Known Corners Cut Under the Timebox:
- **Local SQLite Driver**: Used SQLite with in-memory test configuration for fast evaluation. In production, configure MySQL 8.0+ or PostgreSQL 15+ with native partition tables.
- **Cache Driver**: Default configured to `database` / `array` in test suite; production should bind to a Redis Sentinel or AWS ElastiCache cluster.

---

## 10. Setup & Run Instructions

### Prerequisites
- PHP 8.2+
- Composer 2+
- Node.js 18+ & NPM

### Installation
```bash
# 1. Clone repository
git clone <repo-url>
cd Trail_2

# 2. Install dependencies
composer install
npm install

# 3. Environment configuration
cp .env.example .env
php artisan key:generate

# 4. Migrate and seed exact wireframe data
php artisan migrate:fresh --seed

# 5. Start development servers
php artisan serve
```

### Accessing the Application:
- **Interactive Web Dashboard**: [http://127.0.0.1:8000/merchants/1/dashboard](http://127.0.0.1:8000/merchants/1/dashboard)
- **Dashboard JSON API**: [http://127.0.0.1:8000/api/merchants/1/dashboard](http://127.0.0.1:8000/api/merchants/1/dashboard)
- **Record Usage Endpoint**: `POST http://127.0.0.1:8000/usage` (or `/api/usage`)

---

## 11. Automated Test Suite

A complete test suite is provided covering all edge cases, proration logic, idempotency, rate-limiting, and chunked jobs:

```bash
php artisan test
```

### Test Coverage Highlights:
- `BillingCalculationTest`: Unit test verifying full-cycle ratio, mid-cycle start proration, allowance boundaries, and multi-segment mid-cycle upgrade/downgrade math.
- `UsageIngestionTest`: Feature test verifying successful ingestion (201), idempotent duplicate replay (200), strict validation (422), and rate-limit throttling (429).
- `InvoiceGenerationJobTest`: Feature test verifying queued chunked aggregation of raw events and cycle-end invoice creation with segmented line items.
- `MerchantDashboardTest`: Feature test validating top 5 ranking, projected revenue calculation, MoM >50% churn detection, and Blade rendering.
- `PlanCacheTest`: Feature test verifying plan caching with 10m TTL and model-event cache eviction hooks.
