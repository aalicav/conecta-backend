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
        Schema::create('extemporaneous_negotiations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
            $table->foreignId('tuss_id')->constrained('tuss_procedures');
            $table->decimal('requested_value', 10, 2);
            $table->decimal('approved_value', 10, 2)->nullable();
            $table->text('justification');
            $table->text('approval_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->enum('urgency_level', ['low', 'medium', 'high'])->default('medium');
            
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            
            // Addendum tracking
            $table->boolean('is_requiring_addendum')->default(true);
            $table->boolean('addendum_included')->default(false);
            $table->string('addendum_number')->nullable();
            $table->date('addendum_date')->nullable();
            $table->text('addendum_notes')->nullable();
            $table->foreignId('addendum_updated_by')->nullable()->constrained('users');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('status');
            $table->index('urgency_level');
            $table->index('requested_by');
            $table->index('approved_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('extemporaneous_negotiations');
    }
}; 