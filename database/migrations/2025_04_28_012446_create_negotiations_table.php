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
        Schema::create('negotiations', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->morphs('negotiable'); // Polymorphic relationship to support health plans, professionals, clinics
            $table->foreignId('creator_id')->constrained('users');
            $table->foreignId('contract_template_id')->nullable()->constrained('contract_templates');
            $table->foreignId('contract_id')->nullable()->constrained('contracts');
            $table->enum('status', [
                'draft', 'submitted', 'pending', 'complete', 
                'approved', 'partially_approved', 'rejected', 'cancelled'
            ])->default('draft');
            $table->text('notes')->nullable();
            $table->dateTime('start_date');
            $table->dateTime('end_date');
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('negotiation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negotiation_id')->constrained('negotiations')->cascadeOnDelete();
            $table->unsignedBigInteger('tuss_id');
            // Verifica se a tabela tuss existe antes de criar a chave estrangeira
            if (Schema::hasTable('tuss_procedures')) {
                $table->foreign('tuss_id')->references('id')->on('tuss_procedures');
            }
            $table->decimal('proposed_value', 10, 2);
            $table->decimal('approved_value', 10, 2)->nullable();
            $table->enum('status', [
                'pending', 'approved', 'rejected', 'counter_offered'
            ])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('negotiation_items');
        Schema::dropIfExists('negotiations');
    }
}; 