<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('health_plan_billing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('health_plan_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            
            // Billing Type
            $table->enum('billing_type', [
                'per_appointment',    // Bill for each appointment
                'monthly',           // Monthly billing
                'weekly',           // Weekly billing
                'batch',            // Batch billing (accumulate until threshold)
                'custom'            // Custom billing cycle
            ]);
            
            // Billing Configuration
            $table->integer('billing_day')->nullable(); // Day of month/week for billing
            $table->integer('batch_threshold_amount')->nullable(); // Amount threshold for batch billing
            $table->integer('batch_threshold_appointments')->nullable(); // Appointment count threshold
            $table->integer('payment_term_days')->default(30); // Days to pay after billing
            
            // Financial Settings
            $table->decimal('minimum_billing_amount', 10, 2)->nullable();
            $table->decimal('late_fee_percentage', 5, 2)->nullable();
            $table->decimal('discount_percentage', 5, 2)->nullable();
            $table->integer('discount_if_paid_until_days')->nullable();
            
            // Notification Settings
            $table->boolean('notify_on_generation')->default(true);
            $table->boolean('notify_before_due_date')->default(true);
            $table->integer('notify_days_before')->default(3);
            $table->boolean('notify_on_late_payment')->default(true);
            
            // Status and Metadata
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('health_plan_billing_rules');
    }
}; 