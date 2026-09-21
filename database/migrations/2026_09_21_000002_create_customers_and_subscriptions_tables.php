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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('external_id')->nullable()->index();
            $table->timestamps();

            $table->index(['merchant_id', 'email']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->date('current_cycle_start');
            $table->date('current_cycle_end');
            $table->string('status')->default('active'); // active, cancelled, past_due
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['current_cycle_end', 'status']);
        });

        Schema::create('subscription_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->decimal('prorated_base_price', 10, 2)->default(0.00);
            $table->unsignedBigInteger('prorated_included_units')->default(0);
            $table->decimal('overage_rate_per_unit', 10, 4)->default(0.0000);
            $table->timestamps();

            $table->index(['subscription_id', 'starts_at', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_segments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('customers');
    }
};
