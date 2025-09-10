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
        Schema::create('deliberations', function (Blueprint $table) {
            $table->id();
            
            // Informações básicas da deliberação
            $table->string('deliberation_number')->unique(); // Número único da deliberação
            $table->enum('status', [
                'pending_approval',    // Aguardando aprovação
                'approved',           // Aprovada
                'rejected',           // Rejeitada
                'billed',             // Faturada
                'cancelled'           // Cancelada
            ])->default('pending_approval');
            
            // Relacionamentos
            $table->foreignId('health_plan_id')->constrained('health_plans');
            $table->foreignId('clinic_id')->constrained('clinics');
            $table->foreignId('professional_id')->nullable()->constrained('professionals');
            $table->foreignId('medical_specialty_id')->constrained('medical_specialties');
            $table->foreignId('tuss_procedure_id')->nullable()->constrained('tuss_procedures');
            $table->foreignId('appointment_id')->nullable()->constrained('appointments');
            $table->foreignId('solicitation_id')->nullable()->constrained('solicitations');
            
            // Valores
            $table->decimal('negotiated_value', 10, 2); // Valor negociado com a clínica
            $table->decimal('medlar_percentage', 5, 2); // Percentual da Medlar
            $table->decimal('medlar_amount', 10, 2); // Valor calculado da Medlar
            $table->decimal('total_value', 10, 2); // Valor total (negociado + Medlar)
            $table->decimal('original_table_value', 10, 2)->nullable(); // Valor original da tabela (se existir)
            
            // Motivo e justificativa
            $table->enum('reason', [
                'no_table_value',           // Ausência de valor na tabela
                'specific_doctor_value',    // Valor diferenciado por médico específico
                'special_agreement',        // Acordo especial
                'emergency_case',           // Caso de emergência
                'other'                     // Outro motivo
            ]);
            $table->text('justification'); // Justificativa detalhada
            $table->text('notes')->nullable(); // Observações adicionais
            
            // Aprovação da operadora
            $table->boolean('requires_operator_approval')->default(false);
            $table->boolean('operator_approved')->nullable();
            $table->foreignId('operator_approved_by')->nullable()->constrained('users');
            $table->timestamp('operator_approved_at')->nullable();
            $table->text('operator_approval_notes')->nullable();
            $table->text('operator_rejection_reason')->nullable();
            
            // Aprovação interna
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            
            // Rejeição
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            
            // Cancelamento
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            
            // Faturamento
            $table->foreignId('billing_item_id')->nullable()->constrained('billing_items');
            $table->timestamp('billed_at')->nullable();
            $table->string('billing_batch_number')->nullable();
            
            // Auditoria
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            
            // Timestamps
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->index(['status', 'created_at']);
            $table->index(['health_plan_id', 'status']);
            $table->index(['clinic_id', 'status']);
            $table->index(['medical_specialty_id', 'status']);
            $table->index(['requires_operator_approval', 'operator_approved']);
            $table->index(['billing_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deliberations');
    }
};