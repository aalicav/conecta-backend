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
        Schema::table('value_verifications', function (Blueprint $table) {
            // Add billing integration columns
            $table->foreignId('billing_batch_id')->nullable()->constrained('billing_batches')->onDelete('cascade');
            $table->foreignId('billing_item_id')->nullable()->constrained('billing_items')->onDelete('cascade');
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->onDelete('cascade');
            
            // Add verification metadata
            $table->string('verification_reason')->nullable();
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->timestamp('due_date')->nullable();
            $table->decimal('auto_approve_threshold', 5, 2)->nullable()->comment('Percentage threshold for auto-approval');
            
            // Add indexes for better performance
            $table->index(['billing_batch_id', 'status']);
            $table->index(['billing_item_id', 'status']);
            $table->index(['priority', 'due_date']);
            $table->index(['status', 'due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('value_verifications', function (Blueprint $table) {
            // Drop indexes
            $table->dropIndex(['billing_batch_id', 'status']);
            $table->dropIndex(['billing_item_id', 'status']);
            $table->dropIndex(['priority', 'due_date']);
            $table->dropIndex(['status', 'due_date']);
            
            // Drop columns
            $table->dropForeign(['billing_batch_id']);
            $table->dropForeign(['billing_item_id']);
            $table->dropForeign(['appointment_id']);
            
            $table->dropColumn([
                'billing_batch_id',
                'billing_item_id', 
                'appointment_id',
                'verification_reason',
                'priority',
                'due_date',
                'auto_approve_threshold'
            ]);
        });
    }
}; 