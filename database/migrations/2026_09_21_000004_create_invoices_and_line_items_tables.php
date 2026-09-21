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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('invoice_number')->unique();
            $table->date('cycle_start');
            $table->date('cycle_end');
            $table->decimal('base_amount', 10, 2)->default(0.00);
            $table->decimal('overage_amount', 10, 2)->default(0.00);
            $table->decimal('total_amount', 10, 2)->default(0.00);
            $table->unsignedBigInteger('total_units_consumed')->default(0);
            $table->string('status')->default('finalized'); // draft, finalized, paid
            $table->timestamps();

            $table->index(['customer_id', 'cycle_start', 'cycle_end']);
        });

        Schema::create('invoice_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('description');
            $table->string('type'); // base_fee, overage_fee, prorated_adjustment
            $table->unsignedBigInteger('units')->nullable();
            $table->decimal('unit_rate', 10, 4)->nullable();
            $table->decimal('amount', 10, 2)->default(0.00);
            $table->timestamps();

            $table->index(['invoice_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_line_items');
        Schema::dropIfExists('invoices');
    }
};
