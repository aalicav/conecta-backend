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
            
            // Polymorphic relationship with the entity being negotiated with (clinic or health plan)
            $table->morphs('negotiable');
            
            $table->foreignId('tuss_procedure_id')->constrained('tuss_procedures')->onDelete('cascade');
            $table->decimal('negotiated_price', 10, 2);
            $table->text('justification');
            $table->enum('status', [
                'pending_approval',    // Aguardando aprovação da alçada superior
                'approved',           // Aprovado e liberado para uso
                'rejected',           // Rejeitado pela alçada superior
                'formalized',         // Formalizado via aditivo
                'cancelled'           // Cancelado
            ])->default('pending_approval');
            
            // Tracking fields
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('rejected_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('formalized_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->onDelete('set null');
            
            // Timestamps for each status
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('formalized_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            
            // Contract addendum tracking
            $table->foreignId('contract_id')->nullable()->constrained()->onDelete('set null');
            $table->string('addendum_number')->nullable();
            $table->timestamp('addendum_signed_at')->nullable();
            
            // Notes for each stage
            $table->text('approval_notes')->nullable();
            $table->text('rejection_notes')->nullable();
            $table->text('formalization_notes')->nullable();
            $table->text('cancellation_notes')->nullable();
            
            // Solicitation that triggered this exception (if any)
            $table->foreignId('solicitation_id')->nullable()->constrained()->onDelete('set null');
            
            // Soft deletes and timestamps
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['negotiable_type', 'negotiable_id', 'tuss_procedure_id']);
            $table->index('status');
            $table->index('created_at');
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