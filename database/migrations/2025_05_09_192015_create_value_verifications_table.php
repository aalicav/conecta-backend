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
        Schema::create('value_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type')->comment('Tipo de entidade: extemporaneous_negotiation, contract, etc.');
            $table->unsignedBigInteger('entity_id')->comment('ID da entidade relacionada');
            $table->string('value_type')->comment('Tipo de valor: approved_value, negotiated_value, etc.');
            $table->decimal('original_value', 15, 2)->comment('Valor original inserido');
            $table->decimal('verified_value', 15, 2)->nullable()->comment('Valor verificado (se diferente)');
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->unsignedBigInteger('requester_id')->comment('Usuário que solicitou a verificação');
            $table->unsignedBigInteger('verifier_id')->nullable()->comment('Usuário que verificou o valor');
            $table->text('notes')->nullable()->comment('Observações sobre a verificação');
            $table->timestamp('verified_at')->nullable()->comment('Data e hora da verificação');
            $table->timestamps();
            $table->softDeletes();
            
            // Índices para melhorar a performance
            $table->index(['entity_type', 'entity_id']);
            $table->index('status');
            $table->index('requester_id');
            $table->index('verifier_id');
            
            // Chaves estrangeiras
            $table->foreign('requester_id')->references('id')->on('users');
            $table->foreign('verifier_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('value_verifications');
    }
};
