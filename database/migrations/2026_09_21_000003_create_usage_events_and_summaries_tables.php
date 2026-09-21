<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedBigInteger('units');
            $table->dateTime('usage_date');
            $table->string('idempotency_key', 64)->unique();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();

            // High volume composite indexing (Req 1 & 2)
            $table->index(['customer_id', 'usage_date']);
        });

        // Pre-aggregated daily rollup table to insulate 50L+ row scaling
        Schema::create('daily_usage_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->date('usage_date');
            $table->unsignedBigInteger('total_units')->default(0);
            $table->timestamps();

            $table->unique(['customer_id', 'usage_date']);
            $table->index(['usage_date', 'total_units']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_usage_summaries');
        Schema::dropIfExists('usage_events');
    }
};
