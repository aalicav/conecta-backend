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
        Schema::table('extemporaneous_negotiations', function (Blueprint $table) {
            // Add polymorphic relationship columns if they don't exist
            if (!Schema::hasColumn('extemporaneous_negotiations', 'negotiable_type')) {
                $table->string('negotiable_type');
            }
            if (!Schema::hasColumn('extemporaneous_negotiations', 'negotiable_id')) {
                $table->unsignedBigInteger('negotiable_id');
            }
            
            // Add TUSS procedure relationship if it doesn't exist
            if (!Schema::hasColumn('extemporaneous_negotiations', 'tuss_procedure_id')) {
                $table->foreignId('tuss_procedure_id')->constrained('tuss_procedures')->onDelete('cascade');
            }
            
            // Add negotiated price if it doesn't exist
            if (!Schema::hasColumn('extemporaneous_negotiations', 'negotiated_price')) {
                $table->decimal('negotiated_price', 10, 2);
            }
            
            // Add justification if it doesn't exist
            if (!Schema::hasColumn('extemporaneous_negotiations', 'justification')) {
                $table->text('justification');
            }
            
            // Update status enum if needed
            if (Schema::hasColumn('extemporaneous_negotiations', 'status')) {
                // Drop the existing enum and recreate it
                $table->dropColumn('status');
            }
            $table->enum('status', [
                'pending_approval',    // Aguardando aprovação da alçada superior
                'approved',           // Aprovado e liberado para uso
                'rejected',           // Rejeitado pela alçada superior
                'formalized',         // Formalizado via aditivo
                'cancelled'           // Cancelado
            ])->default('pending_approval');
            
            // Add tracking fields if they don't exist
            if (!Schema::hasColumn('extemporaneous_negotiations', 'created_by')) {
                $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            }
            if (!Schema::hasColumn('extemporaneous_negotiations', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('extemporaneous_negotiations', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->constrained('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('extemporaneous_negotiations', 'formalized_by')) {
                $table->foreignId('formalized_by')->nullable()->constrained('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('extemporaneous_negotiations', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->onDelete('set null');
            }
            
            // Add timestamps for each status if they don't exist
            if (!Schema::hasColumn('extemporaneous_negotiations', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (!Schema::hasColumn('extemporaneous_negotiations', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable();
            }
            if (!Schema::hasColumn('extemporaneous_negotiations', 'formalized_at')) {
                $table->timestamp('formalized_at')->nullable();
            }
            if (!Schema::hasColumn('extemporaneous_negotiations', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
            
            // Add contract addendum tracking if they don't exist
            if (!Schema::hasColumn('extemporaneous_negotiations', 'contract_id')) {
                $table->foreignId('contract_id')->nullable()->constrained()->onDelete('set null');
            }
            if (!Schema::hasColumn('extemporaneous_negotiations', 'addendum_number')) {
                $table->string('addendum_number')->nullable();
            }
            if (!Schema::hasColumn('extemporaneous_negotiations', 'addendum_signed_at')) {
                $table->timestamp('addendum_signed_at')->nullable();
            }
            
            // Add notes for each stage if they don't exist
            if (!Schema::hasColumn('extemporaneous_negotiations', 'approval_notes')) {
                $table->text('approval_notes')->nullable();
            }
            if (!Schema::hasColumn('extemporaneous_negotiations', 'rejection_notes')) {
                $table->text('rejection_notes')->nullable();
            }
            if (!Schema::hasColumn('extemporaneous_negotiations', 'formalization_notes')) {
                $table->text('formalization_notes')->nullable();
            }
            if (!Schema::hasColumn('extemporaneous_negotiations', 'cancellation_notes')) {
                $table->text('cancellation_notes')->nullable();
            }
            
            // Add solicitation relationship if it doesn't exist
            if (!Schema::hasColumn('extemporaneous_negotiations', 'solicitation_id')) {
                $table->foreignId('solicitation_id')->nullable()->constrained()->onDelete('set null');
            }
            
            // Add soft deletes if it doesn't exist
            if (!Schema::hasColumn('extemporaneous_negotiations', 'deleted_at')) {
                $table->softDeletes();
            }
            
            // Add indexes if they don't exist
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
        Schema::table('extemporaneous_negotiations', function (Blueprint $table) {
            // Drop indexes
            $table->dropIndex(['negotiable_type', 'negotiable_id', 'tuss_procedure_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['created_at']);
            
            // Drop foreign keys
            $table->dropForeign(['tuss_procedure_id']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropForeign(['formalized_by']);
            $table->dropForeign(['cancelled_by']);
            $table->dropForeign(['contract_id']);
            $table->dropForeign(['solicitation_id']);
            
            // Drop columns
            $table->dropColumn([
                'negotiable_type',
                'negotiable_id',
                'tuss_procedure_id',
                'negotiated_price',
                'justification',
                'status',
                'created_by',
                'approved_by',
                'rejected_by',
                'formalized_by',
                'cancelled_by',
                'approved_at',
                'rejected_at',
                'formalized_at',
                'cancelled_at',
                'contract_id',
                'addendum_number',
                'addendum_signed_at',
                'approval_notes',
                'rejection_notes',
                'formalization_notes',
                'cancellation_notes',
                'solicitation_id',
                'deleted_at'
            ]);
        });
    }
}; 