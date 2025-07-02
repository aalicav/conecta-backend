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
        Schema::create('health_plan_procedures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('health_plan_id')->constrained()->onDelete('cascade');
            $table->foreignId('tuss_procedure_id')->constrained()->onDelete('cascade');
            $table->decimal('price', 10, 2);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('start_date')->default(now());
            $table->date('end_date')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->index(['health_plan_id', 'tuss_procedure_id']);
            $table->index(['health_plan_id', 'is_active']);
            $table->index('is_active');
            
            // Unique constraint to prevent duplicate procedures for the same health plan
            $table->unique(['health_plan_id', 'tuss_procedure_id'], 'unique_health_plan_procedure');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('health_plan_procedures');
    }
}; 