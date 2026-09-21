<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DailyUsageSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'usage_date',
        'total_units',
    ];

    protected function casts(): array
    {
        return [
            'total_units' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Increment units atomically for a customer on a given day.
     */
    public static function incrementUnits(int $customerId, string $date, int $units): self
    {
        $dateStr = Carbon::parse($date)->toDateString();

        return DB::transaction(function () use ($customerId, $dateStr, $units) {
            $summary = static::where('customer_id', $customerId)
                ->where('usage_date', $dateStr)
                ->lockForUpdate()
                ->first();

            if (! $summary) {
                try {
                    $summary = static::create([
                        'customer_id' => $customerId,
                        'usage_date' => $dateStr,
                        'total_units' => 0,
                    ]);
                } catch (QueryException $e) {
                    // Handle race condition on unique index
                    $summary = static::where('customer_id', $customerId)
                        ->where('usage_date', $dateStr)
                        ->lockForUpdate()
                        ->firstOrFail();
                }
            }

            $summary->increment('total_units', $units);

            return $summary->fresh();
        });
    }
}
