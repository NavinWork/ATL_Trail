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
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('api_key', 64)->unique();
            $table->string('currency', 3)->default('INR'); // e.g. INR (₹), USD ($)
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('name'); // e.g. Growth
            $table->string('code')->index(); // e.g. growth-monthly
            $table->decimal('base_price', 10, 2)->default(0.00);
            $table->string('billing_cycle')->default('monthly'); // monthly, yearly
            $table->unsignedBigInteger('included_usage_units')->default(0); // e.g. 250,000 units
            $table->decimal('overage_rate_per_unit', 10, 4)->default(0.0000); // rate per unit over allowance
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['merchant_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
        Schema::dropIfExists('merchants');
    }
};
