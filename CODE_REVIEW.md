# Pull Request Review: Usage Ingestion Endpoint (`POST /usage`)

**Reviewer**: Associate Team Lead  
**PR Author**: Mid-Level Backend Engineer  
**PR Branch**: `feature/usage-recording-endpoint`  
**Status**: 🔴 Changes Requested  

---

### Lead Review Summary

Hey! Thanks for getting this initial implementation of the usage recording endpoint together so quickly. The core intent is clear and the endpoint is concise. 

However, because this endpoint sits directly in the **critical write path** of our multi-tenant metering and billing platform, it will experience high throughput and directly impacts our customers' money and trust. There are critical architectural, security, and data integrity concerns that need to be resolved before this can ship to staging or production.

I've documented line-by-line feedback below, followed by a proposed refactor and the architectural rationale behind each change.

---

### Line-by-Line Code Review

```php
1: public function store(Request $request)
2: {
3:     $customer = Customer::find($request->customer_id);
4:     $usage = new UsageEvent();
5:     $usage->customer_id = $customer->id;
6:     $usage->units = $request->units;
7:     $usage->usage_date = $request->usage_date;
8:     $usage->save();
9:     return response()->json(['status' => 'ok']);
10: }
```

#### Line 1: Missing Form Request & Input Validation
* **Issue**: `$request` is unvalidated `Illuminate\Http\Request`. Any data can be submitted:
  - `units` can be negative (which would artificially decrement billing or crash overage calculations), a string, or floating point numbers prone to rounding errors.
  - `usage_date` can be an invalid date, in the future, or malformed.
  - `customer_id` is not validated for type or presence.
* **Fix**: Extract validation into a dedicated `RecordUsageRequest` FormRequest with strict typing (`integer`, `min:1`, `date`, `exists:customers,id`).

#### Line 3: Fatal Null Pointer Exception & Missing Tenant Scoping
* **Issue 1 (NPE Crash)**: `Customer::find(...)` returns `null` if the ID is missing or invalid. In Line 5, `$customer->id` will trigger a fatal PHP error: `Attempt to read property "id" on null`, returning an unhandled `500 Internal Server Error`.
* **Issue 2 (Multi-Tenant Breach)**: There is no authorization or tenant scoping check. Any authenticated API client could submit another merchant's `customer_id` and record arbitrary usage against that merchant's billing.
* **Fix**: Ensure the customer is resolved in scope of the authenticated Merchant (`$request->user()->merchant->customers()->findOrFail(...)` or route binding) and handled via FormRequest validation rules.

#### Lines 4–8: No Idempotency (Double-Billing Risk)
* **Issue**: High-throughput distributed clients (API gateways, SDKs, webhooks) frequently retry requests upon network timeouts or transient socket drops. Without an **Idempotency Key**, every retry will insert a duplicate `UsageEvent`, causing duplicate charges and customer invoice disputes.
* **Fix**: Require an `idempotency_key` (UUID or client-generated unique string) in the payload or via an `Idempotency-Key` header. Back this with a database unique index (`customer_id, idempotency_key`) to guarantee idempotency at the database engine level.

#### Line 8: Synchronous Database Bottleneck at High Volume
* **Issue**: Performing a direct, synchronous row insert per request at high write volumes (e.g. thousands of writes/sec) will saturate connection pools and drive up p99 latency.
* **Fix**: In this endpoint, insert with conflict detection (or dispatch to a queued worker / buffered ingestion pipeline like Redis Stream / Kafka for massive write volume). Additionally, update daily summary rollups (`daily_usage_summaries`) so reporting queries don't have to scan millions of raw event rows.

#### Line 9: HTTP Status Code & Response Semantics
* **Issue**: A successful resource creation in REST API standards should return `201 Created` (or `202 Accepted` if queued asynchronously), rather than a generic `200 OK`. It should also indicate whether the request was processed as a fresh insert or deduplicated as an idempotent replay.
* **Fix**: Return `201 Created` with resource metadata on fresh insert, and `200 OK` with `'idempotent' => true` on duplicate replayed requests.

---

### Recommended Implementation

#### 1. Form Request (`app/Http/Requests/RecordUsageRequest.php`)
```php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Enforce tenant authorization if using API key / Sanctum
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id'     => ['required', 'integer', 'exists:customers,id'],
            'units'           => ['required', 'integer', 'min:1'],
            'usage_date'      => ['required', 'date'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
```

#### 2. Controller Action (`app/Http/Controllers/UsageController.php`)
```php
public function store(RecordUsageRequest $request): JsonResponse
{
    $validated = $request->validated();
    $idempotencyKey = $validated['idempotency_key'];

    // 1. Fast path: Return cached / existing event if replayed
    $existing = UsageEvent::where('idempotency_key', $idempotencyKey)->first();
    if ($existing) {
        return response()->json([
            'status'     => 'ok',
            'idempotent' => true,
            'message'    => 'Event already processed.',
            'event_id'   => $existing->id,
        ], 200);
    }

    // 2. Atomic insert protected against concurrency races
    try {
        $event = DB::transaction(function () use ($validated, $idempotencyKey) {
            $created = UsageEvent::create([
                'customer_id'     => $validated['customer_id'],
                'units'           => $validated['units'],
                'usage_date'      => $validated['usage_date'],
                'idempotency_key' => $idempotencyKey,
                'processed_at'    => now(),
            ]);

            // Update daily rollup table to decouple 50L+ raw scans from dashboard & billing
            DailyUsageSummary::incrementUnits(
                $validated['customer_id'],
                Carbon::parse($validated['usage_date'])->toDateString(),
                $validated['units']
            );

            return $created;
        });

        return response()->json([
            'status'     => 'ok',
            'idempotent' => false,
            'event_id'   => $event->id,
        ], 201);
    } catch (QueryException $e) {
        // Fallback for concurrent duplicate requests hitting unique constraint
        if ($e->getCode() == 23000) {
            $existing = UsageEvent::where('idempotency_key', $idempotencyKey)->firstOrFail();
            return response()->json([
                'status'     => 'ok',
                'idempotent' => true,
                'event_id'   => $existing->id,
            ], 200);
        }
        throw $e;
    }
}
```

Let me know if you want to pair on implementing the idempotency migration or setting up the test cases! Great work getting the foundation started.
