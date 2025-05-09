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
        Schema::create('scheduling_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitation_id')->constrained()->onDelete('cascade');
            $table->foreignId('provider_type');
            $table->foreignId('provider_id');
            $table->string('provider_type_class'); // Classe do modelo do provedor (App\Models\Clinic ou App\Models\Professional)
            $table->string('provider_name');
            $table->decimal('provider_price', 10, 2); // Preço do procedimento com este provedor
            $table->decimal('recommended_provider_price', 10, 2)->nullable(); // Preço do provedor recomendado (para comparação)
            $table->text('justification')->nullable(); // Justificativa para a exceção
            $table->foreignId('requested_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduling_exceptions');
    }
};
