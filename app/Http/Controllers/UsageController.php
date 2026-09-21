<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecordUsageRequest;
use App\Models\DailyUsageSummary;
use App\Models\UsageEvent;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UsageController extends Controller
{
    /**
     * Record a usage event with strict idempotency and high-throughput safety.
     */
    public function store(RecordUsageRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $idempotencyKey = $validated['idempotency_key'];

        // 1. Check existing record first (fast path)
        $existing = UsageEvent::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return response()->json([
                'status' => 'ok',
                'idempotent' => true,
                'message' => 'Usage event previously recorded (idempotent replay).',
                'event_id' => $existing->id,
            ], 200);
        }

        // 2. High-throughput atomic insert with concurrency race protection
        try {
            $event = DB::transaction(function () use ($validated, $idempotencyKey) {
                $usageDate = Carbon::parse($validated['usage_date']);

                $created = UsageEvent::create([
                    'customer_id' => $validated['customer_id'],
                    'units' => $validated['units'],
                    'usage_date' => $usageDate,
                    'idempotency_key' => $idempotencyKey,
                    'processed_at' => now(),
                ]);

                // Immediately update the daily summary rollup to keep dashboard fast and current
                DailyUsageSummary::incrementUnits(
                    $validated['customer_id'],
                    $usageDate->toDateString(),
                    $validated['units']
                );

                return $created;
            });

            return response()->json([
                'status' => 'ok',
                'idempotent' => false,
                'message' => 'Usage event recorded successfully.',
                'event_id' => $event->id,
            ], 201);
        } catch (QueryException $e) {
            // Check if concurrency race hit the unique constraint on idempotency_key
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Integrity constraint') || $e->getCode() == 23000) {
                $existing = UsageEvent::where('idempotency_key', $idempotencyKey)->first();

                return response()->json([
                    'status' => 'ok',
                    'idempotent' => true,
                    'message' => 'Usage event previously recorded (concurrency deduplicated).',
                    'event_id' => $existing?->id,
                ], 200);
            }

            Log::error('Usage ingestion error: '.$e->getMessage());
            throw $e;
        }
    }
}
