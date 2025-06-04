<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'paid', 'cancelled', 'refunded'])->default('pending');
            $table->enum('type', ['subscription', 'appointment', 'service', 'refund', 'other']);
            $table->string('description')->nullable();
            
            // Polymorphic relationship with entities (clinics, professionals, health plans)
            $table->morphs('entity');
            
            // Related records
            $table->foreignId('subscription_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('health_plan_id')->nullable()->constrained()->onDelete('set null');
            
            // Payment information
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method')->nullable();
            $table->json('payment_details')->nullable();
            $table->string('invoice_number')->nullable();
            
            // Audit fields
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['status', 'due_date']);
            $table->index('health_plan_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('transactions');
    }
}; 