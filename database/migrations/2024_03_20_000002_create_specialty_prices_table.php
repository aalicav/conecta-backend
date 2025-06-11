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
        Schema::create('specialty_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_specialty_id')->constrained()->onDelete('cascade');
            $table->foreignId('negotiation_id')->constrained()->onDelete('cascade');
            $table->decimal('proposed_value', 10, 2);
            $table->decimal('approved_value', 10, 2)->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            // Índices para performance
            $table->index('status');
            $table->index('approved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('specialty_prices');
    }
}; 