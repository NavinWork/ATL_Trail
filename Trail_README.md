# Subscription Billing & Usage-Metering System

## Overview

This project is a small Laravel application for a multi-tenant SaaS billing system.

The main purpose of the application is to:

- Manage merchants and their plans
- Allow customers to subscribe to plans
- Record customer usage
- Aggregate usage for billing
- Calculate overage charges
- Handle subscription changes during a billing cycle
- Generate invoices
- Provide a merchant dashboard
- Keep usage recording safe when requests are retried

The project is implemented using Laravel 12 and PHP 8.2.

---

## 1. Main Flow

The basic flow is:

```text
Merchant
   |
   +-- Plans
   |
   +-- Customers
          |
          +-- Subscription
                  |
                  +-- Usage Events
                         |
                         v
                  Daily Usage Summary
                         |
                         v
                    Billing Job
                         |
                         v
                      Invoice
```

The usage endpoint is kept simple because usage events can be received frequently. The heavier aggregation and billing work is handled through queued jobs.

---

## 2. Main Entities

### Merchant

A merchant is the tenant in the system.

A merchant can have:

- Multiple plans
- Multiple customers

All customer and plan data belongs to a merchant.

### Plan

A plan contains:

- Plan name
- Base price
- Billing cycle
- Included usage units
- Overage price per unit

Example:

```text
Plan: Growth
Price: ₹1,000 / month
Included usage: 10,000 units
Overage: ₹0.10 per unit
```

### Customer

A customer belongs to a merchant and can subscribe to a plan.

### Subscription

A subscription stores the customer's active plan and billing cycle.

### Subscription Segment

A subscription can change during a billing cycle.

For example:

```text
Day 1 - Day 10  -> Plan A
Day 11 - Day 30 -> Plan B
```

Each period is treated as a separate subscription segment so that usage is billed using the correct plan.

### Usage Event

This is the raw usage record received from the usage API.

Usage events are treated as append-only records.

### Daily Usage Summary

Raw usage events can become very large.

Instead of calculating reports and invoices by scanning every raw event, daily usage is summarized per customer.

Example:

```text
Customer 101
2026-09-01 -> 500 units
2026-09-02 -> 750 units
2026-09-03 -> 620 units
```

This makes dashboard and billing queries much smaller.

### Invoice

An invoice contains the base subscription charge and any applicable overage charges.

---

# 3. Database Design

The main tables are:

```text
merchants
plans
customers
subscriptions
subscription_segments
usage_events
daily_usage_summaries
invoices
invoice_line_items
```

The `usage_events` table is expected to contain a large number of records.

Important indexes include:

```text
usage_events
-------------------------------
idempotency_key
customer_id + usage_date
processed_at + id
```

The idempotency key is unique so that the same usage request cannot be counted more than once.

The customer/date index helps when reading usage for a particular customer and period.

The processed/date-related index helps background jobs find records that still need processing.

---

# 4. Handling Large Usage Data

The requirement mentions 50 lakh+ usage-event records.

I have kept the raw usage events separate from the daily summaries.

For example, instead of calculating a monthly report from:

```text
5,000,000 usage rows
```

the application can use:

```text
Customer + Day
```

records from the daily summary table.

For example:

```text
Customer 1
30 daily records

Customer 2
30 daily records

...

1,000 customers
30,000 daily records
```

This greatly reduces the amount of data that needs to be read for normal billing and dashboard operations.

### Future production improvement

If usage volume becomes much higher, I would consider monthly database partitioning for the `usage_events` table.

For very high write volumes, a queue or streaming layer such as Redis Streams or Kafka could also be introduced before writing the events into the database.

These are considered future scaling improvements rather than requirements for the current exercise.

---

# 5. Usage API

The main endpoint is:

```http
POST /usage
```

Example request:

```json
{
    "customer_id": 101,
    "units": 25,
    "usage_date": "2026-09-23",
    "idempotency_key": "usage-12345"
}
```

The endpoint validates the request and records the usage.

## Idempotency

Usage requests may be retried because of network problems.

For example:

```text
Request 1 -> saved
Request 2 -> retry
Request 3 -> retry
```

All three requests should result in only one usage event.

The application handles this using a unique `idempotency_key`.

There is also a check before inserting the record. The database unique constraint provides the final protection if two identical requests arrive at the same time.

---

# 6. Rate Limiting

The usage endpoint has basic rate limiting.

The current configuration allows:

```text
120 requests per minute per API key
```

This prevents one client from continuously sending requests and affecting the application.

The limit can be changed based on actual production traffic.

---

# 7. Usage Aggregation

Raw usage records are processed by a queued job.

The general process is:

```text
Usage Events
     |
     v
Queue Job
     |
     v
Process records in chunks
     |
     v
Daily Usage Summary
```

The records are processed in chunks instead of loading all usage records into memory at once.

The current chunk size is:

```text
5,000 records
```

This keeps memory usage under control when processing large volumes.

---

# 8. Billing Calculation

At the end of the billing cycle, the application generates an invoice.

The basic calculation is:

```text
Base Plan Charge
+
Overage Charge
=
Invoice Amount
```

For example:

```text
Plan price       = ₹1,000
Included usage   = 10,000
Actual usage     = 12,500
Overage rate     = ₹0.10
```

Calculation:

```text
Overage units = 12,500 - 10,000
              = 2,500

Overage charge = 2,500 × ₹0.10
               = ₹250

Total invoice = ₹1,000 + ₹250
              = ₹1,250
```

If usage does not exceed the included allowance, there is no overage charge.

---

# 9. Proration

Proration is required when a customer starts a subscription in the middle of a billing cycle.

For example, if a monthly plan costs ₹3,000 and the customer is active for half of a 30-day billing cycle:

```text
₹3,000 × 15 / 30
= ₹1,500
```

The same approach is used for the included usage allowance.

The exact calculation is based on the number of active days in the billing cycle.

---

# 10. Upgrade / Downgrade During a Cycle

A customer can change their plan before the billing cycle ends.

Example:

```text
Day 1 - Day 10
Plan A

Day 11 - Day 30
Plan B
```

The usage before the change is billed using Plan A.

The usage after the change is billed using Plan B.

The application therefore stores subscription segments instead of relying only on the customer's current plan.

For each segment, the system calculates:

```text
Active days
Plan price
Prorated base charge
Prorated included usage
Usage during the segment
Overage
```

This avoids using the new plan to incorrectly calculate usage that happened before the plan change.

---

# 11. Plan Cache

Plan and pricing information is read frequently, so it is cached.

Example cache keys:

```text
plan:pricing:{plan_id}
merchant:{merchant_id}:active_plans
```

The current cache lifetime is:

```text
10 minutes
```

When a plan is updated or deleted, the related cache is cleared.

If the cache is unavailable, the application can read the plan directly from the database.

For a production environment, Redis would be the preferred cache store.

---

# 12. Merchant Dashboard

The dashboard is available through:

```http
GET /merchants/{id}/dashboard
```

and also through the JSON API:

```http
GET /api/merchants/{id}/dashboard
```

The dashboard shows:

### Current usage

Current customer usage compared with the included allowance.

### Projected overage revenue

An estimate of the overage revenue for the current billing cycle based on current usage.

### Top 5 customers

The five customers with the highest usage during the current cycle.

### Churn risk

Customers whose usage has dropped by more than 50% compared with the previous month.

Example:

```text
Previous month = 10,000
Current month  = 4,000

Drop = 60%

Customer is shown as churn risk
```

### Usage trend

The dashboard also shows the recent daily usage trend.

---

# 13. Code Review

The task included a sample usage-recording method for review.

The original implementation directly finds a customer and saves a usage record.

The main problems identified are:

1. No request validation
2. Invalid customer IDs are not handled properly
3. Negative or invalid usage values could be accepted
4. No tenant/merchant ownership validation
5. No idempotency protection
6. Duplicate requests can create duplicate usage
7. No rate limiting
8. It performs database work synchronously without considering high-volume traffic

The improved implementation addresses these concerns through validation, authorization/tenant checks, idempotency, rate limiting and better request handling.

---

# 14. Rollout and Monitoring

For a production rollout, I would introduce the system gradually.

A simple approach would be:

```text
Deploy database changes
        |
        v
Deploy application
        |
        v
Test with internal merchant
        |
        v
Monitor usage recording
        |
        v
Enable for more merchants
```

For database changes on large tables, indexes and migrations should be planned carefully so they do not unnecessarily block usage writes.

## Main monitoring point

The first thing I would monitor is the usage ingestion rate.

For example:

```text
Expected usage events: 10,000/min
Actual usage events:   2,000/min
```

A sudden unexpected drop can indicate that clients, webhooks, queues or the usage API have stopped working.

This is important because the application could otherwise continue running while usage is silently missed, which would eventually result in incorrect billing.

---

# 15. Known Limitations / Future Improvements

This is a mini-task, so some production features are intentionally kept simple.

### Database

The local setup can use SQLite for easy development and testing.

For production with large usage volumes, I would use:

```text
MySQL 8+
or
PostgreSQL 15+
```

with appropriate indexes and, if required, table partitioning.

### Cache

The application can run without Redis in the local setup.

For production, Redis would be preferred.

### Very high write volume

If usage grows beyond what the application/database can comfortably handle, I would introduce:

```text
API
 ↓
Redis Stream / Kafka
 ↓
Workers
 ↓
Database
```

This would allow the API layer to handle traffic without making every request wait for database processing.

### Multi-currency and timezone

The current implementation keeps currency and timezone handling simple.

A production version supporting global merchants should define:

- Merchant currency
- Customer timezone
- Billing timezone
- Currency conversion rules if required

---

# 16. Setup

## Requirements

- PHP 8.2+
- Composer 2+
- Node.js 18+
- NPM

## Installation

Clone the repository:

```bash
git clone <repo-url>
cd Trail_2
```

Install dependencies:

```bash
composer install
npm install
```

Create the environment file:

```bash
cp .env.example .env
php artisan key:generate
```

Run migrations and seed data:

```bash
php artisan migrate:fresh --seed
```

Start Laravel:

```bash
php artisan serve
```

The application will normally be available at:

```text
http://127.0.0.1:8000
```

---

# 17. Important URLs

Dashboard:

```text
http://127.0.0.1:8000/merchants/1/dashboard
```

Dashboard API:

```text
http://127.0.0.1:8000/api/merchants/1/dashboard
```

Usage API:

```text
POST http://127.0.0.1:8000/usage
```

---

# 18. Running Tests

Run the complete test suite:

```bash
php artisan test
```

The tests cover the main areas of the task, including:

- Billing calculation
- Proration
- Overage calculation
- Mid-cycle plan changes
- Usage ingestion
- Idempotency
- Validation
- Rate limiting
- Usage aggregation
- Invoice generation
- Dashboard calculations
- Plan caching

---

# 19. Handing This Off

If another developer continues this project, I would highlight these areas first:

### 1. Usage volume

If usage grows significantly, review the current synchronous database write approach and consider Redis Streams or Kafka.

### 2. Database partitioning

If the usage-events table continues to grow quickly, introduce a proper partition creation and archival process.

### 3. Billing rules

Any changes to billing, proration or plan-change behavior should be covered by tests because these calculations directly affect customer invoices.

---

# 20. Summary

The main design decisions in this project are:

```text
High-volume usage
        ↓
Raw usage events
        ↓
Idempotent writes
        ↓
Queued chunk processing
        ↓
Daily usage summaries
        ↓
Billing calculation
        ↓
Invoice
```

The application keeps the raw usage data for accuracy while using daily summaries for normal reporting and billing.

Subscription segments are used to correctly handle plan upgrades and downgrades during a billing cycle.

The implementation focuses on the requirements of the assessment while keeping the local setup simple enough to run and review easily.

# 21. Manual Testing & API Examples

This section contains some manual commands and API examples that can be used to test the application locally.

---

## 21.1 Create a New Customer

Customers need to exist before creating a subscription.

A customer can be created using Laravel Tinker.

Run:

```bash
php artisan tinker
```

Then:

```php
$customer = App\Models\Customer::create([
    'merchant_id' => 1,
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);
```

To check the newly created customer ID:

```php
$customer->id;
```

Use this customer ID when creating a subscription.

---

## 21.2 Subscription APIs

The application provides APIs for creating, viewing and changing subscriptions.

### 1. Create a New Subscription

Creates a new subscription for an existing customer and plan.

**Method**

```http
POST /api/subscriptions
```

**Headers**

```text
Accept: application/json
Content-Type: application/json
```

**Request**

```json
{
    "customer_id": 5,
    "plan_id": 2,
    "start_date": "2026-09-25"
}
```

`start_date` is optional. If it is not provided, the application uses the current date.

---

### 2. View a Subscription

Returns the details of a subscription, including its current plan, billing-cycle dates, status and subscription segments.

**Method**

```http
GET /api/subscriptions/{subscription_id}
```

**Headers**

```text
Accept: application/json
```

**Example**

```http
GET /api/subscriptions/1
```

The response contains the subscription information along with its related plan and subscription segment data.

---

### 3. Change Subscription Plan

This API is used when a customer upgrades or downgrades their plan during an active billing cycle.

A new subscription segment is created for the new plan. The billing calculation uses the appropriate plan for usage before and after the change.

**Method**

```http
POST /api/subscriptions/{subscription_id}/change-plan
```

**Headers**

```text
Accept: application/json
Content-Type: application/json
```

**Request**

```json
{
    "plan_id": 3,
    "transition_time": "2026-09-25"
}
```

`transition_time` is optional. If it is not provided, the current time is used.

---

## 21.3 Other Main APIs

### Record Usage

Records a usage event for a customer.

```http
POST /api/usage
```

Example request:

```json
{
    "customer_id": 5,
    "units": 100,
    "usage_date": "2026-09-25",
    "idempotency_key": "usage-12345"
}
```

The `idempotency_key` is used to make sure that retrying the same request does not create duplicate usage.

---

### Merchant Dashboard

Returns the data used by the merchant dashboard.

```http
GET /api/merchants/{id}/dashboard
```

Example:

```http
GET /api/merchants/1/dashboard
```

The response includes the dashboard metrics such as customer usage, projected overage revenue, top customers and usage-drop/churn-risk information.

---

## 21.4 Generate an Invoice Manually

For local testing, an invoice can be generated manually using Laravel Tinker.

This is useful when you want to verify the billing calculation without waiting for the actual billing cycle to end.

Start Tinker:

```bash
php artisan tinker
```

Then run:

```php
$sub = App\Models\Subscription::whereDoesntHave('invoices')->first();

if ($sub) {
    $sub->update([
        'current_cycle_end' => now()->subDay()->toDateString()
    ]);

    echo "Forced end of cycle for Subscription #{$sub->id}\n";

    $job = new App\Jobs\GenerateCycleInvoicesJob(now()->toDateString());

    $job->handle(
        app(App\Services\BillingCalculationService::class)
    );

    echo "Job finished running!\n";

    $invoice = App\Models\Invoice::with('lineItems')
        ->where('subscription_id', $sub->id)
        ->latest()
        ->first();

    echo "\n--- NEWLY GENERATED INVOICE ---\n";

    echo json_encode(
        $invoice,
        JSON_PRETTY_PRINT
    );
} else {
    echo "All subscriptions are already invoiced! Try creating a new subscription first.\n";
}
```

### What this does

The command:

1. Finds a subscription that does not already have an invoice.
2. Changes its current cycle end date to yesterday.
3. Runs the `GenerateCycleInvoicesJob`.
4. Uses the application's `BillingCalculationService` to calculate the invoice.
5. Fetches the newly generated invoice with its line items.
6. Prints the invoice details in JSON format.

This is only intended for **local/manual testing**. It should not be used as the normal production invoice-generation process.

---

## 21.5 Suggested Manual Test Flow

For a quick end-to-end test, the following order can be used:

```text
1. Create Customer
       ↓
2. Create Subscription
       ↓
3. Record Usage
       ↓
4. View Dashboard
       ↓
5. Change Subscription Plan
       ↓
6. Record More Usage
       ↓
7. Generate Invoice
       ↓
8. Check Invoice & Line Items
```

This flow allows the main subscription, usage-metering and billing functionality to be tested together.
