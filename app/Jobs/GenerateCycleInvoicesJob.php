<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\BillingCalculationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateCycleInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $chunkSize;
    public ?string $targetDate;

    public function __construct(?string $targetDate = null, int $chunkSize = 250)
    {
        $this->targetDate = $targetDate;
        $this->chunkSize = $chunkSize;
    }

    public function handle(BillingCalculationService $billingService): void
    {
        $evalDate = $this->targetDate ? Carbon::parse($this->targetDate) : Carbon::today();
        Log::info("Starting GenerateCycleInvoicesJob for date: {$evalDate->toDateString()}");

        // First, ensure all pending events up to today are aggregated
        (new AggregateDailyUsageJob())->handle();

        // Process subscriptions whose cycle has ended on or before evalDate
        Subscription::where('status', 'active')
            ->where('current_cycle_end', '<=', $evalDate->toDateString())
            ->orderBy('id')
            ->chunkById($this->chunkSize, function ($subscriptions) use ($billingService) {
                foreach ($subscriptions as $subscription) {
                    // Prevent duplicate invoices for the same cycle
                    $alreadyInvoiced = Invoice::where('subscription_id', $subscription->id)
                        ->where('cycle_start', $subscription->current_cycle_start)
                        ->where('cycle_end', $subscription->current_cycle_end)
                        ->exists();

                    if (!$alreadyInvoiced) {
                        $invoice = $billingService->generateInvoiceForSubscription($subscription);
                        Log::info("Generated invoice {$invoice->invoice_number} for customer {$subscription->customer_id}: Total {$invoice->total_amount}");
                    }
                }
            });

        Log::info("Completed GenerateCycleInvoicesJob run.");
    }
}
