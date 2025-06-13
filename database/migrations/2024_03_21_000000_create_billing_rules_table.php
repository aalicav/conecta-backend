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
        Schema::create('billing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('health_plan_id')->constrained()->onDelete('cascade');
            $table->foreignId('contract_id')->constrained()->onDelete('cascade');
            $table->string('frequency'); // monthly, weekly, daily
            $table->integer('monthly_day')->nullable(); // Day of month for monthly frequency
            $table->integer('batch_size')->default(100); // Maximum number of items per batch
            $table->integer('payment_days')->default(30); // Payment deadline in days
            $table->json('notification_recipients')->nullable(); // Array of email addresses
            $table->string('notification_frequency')->default('daily'); // daily, weekly, monthly
            $table->string('document_format')->default('pdf'); // pdf, xml, json
            $table->foreignId('guide_template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Add unique constraint to prevent duplicate rules for the same contract
            $table->unique(['health_plan_id', 'contract_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_rules');
    }
}; 