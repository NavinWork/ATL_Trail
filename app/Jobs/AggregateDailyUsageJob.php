<?php

namespace App\Jobs;

use App\Models\DailyUsageSummary;
use App\Models\UsageEvent;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AggregateDailyUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Chunk size for processing 50L+ high-volume records without memory exhaustion.
     */
    public int $chunkSize;

    public function __construct(int $chunkSize = 5000)
    {
        $this->chunkSize = $chunkSize;
    }

    public function handle(): void
    {
        Log::info("Starting AggregateDailyUsageJob with chunk size {$this->chunkSize}");

        // Chunk through unaggregated usage events by ID
        UsageEvent::whereNull('processed_at')
            ->orderBy('id')
            ->chunkById($this->chunkSize, function ($events) {
                DB::transaction(function () use ($events) {
                    $eventIds = [];

                    // Group events in memory by customer_id and usage_date
                    $grouped = [];
                    foreach ($events as $event) {
                        $eventIds[] = $event->id;
                        $date = Carbon::parse($event->usage_date)->toDateString();
                        $key = "{$event->customer_id}_{$date}";

                        if (!isset($grouped[$key])) {
                            $grouped[$key] = [
                                'customer_id' => $event->customer_id,
                                'date' => $date,
                                'units' => 0,
                            ];
                        }
                        $grouped[$key]['units'] += $event->units;
                    }

                    // Bulk update/upsert daily summaries
                    foreach ($grouped as $item) {
                        DailyUsageSummary::incrementUnits(
                            $item['customer_id'],
                            $item['date'],
                            $item['units']
                        );
                    }

                    // Mark processed
                    UsageEvent::whereIn('id', $eventIds)->update([
                        'processed_at' => now(),
                    ]);
                });
            });

        Log::info("Completed AggregateDailyUsageJob run.");
    }
}
