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
        Schema::create('professional_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->onDelete('cascade');
            $table->foreignId('patient_id')->constrained()->onDelete('cascade');
            $table->foreignId('professional_id')->constrained('users')->onDelete('cascade');
            $table->string('category'); // promoter, neutral, detractor
            $table->string('score_range'); // 0-6, 7-8, 9-10
            $table->string('phone');
            $table->string('source')->default('whatsapp_button'); // whatsapp_button, web, app
            $table->timestamp('responded_at');
            $table->text('comments')->nullable();
            $table->timestamps();
            
            $table->index(['appointment_id', 'patient_id']);
            $table->index(['professional_id', 'category']);
            $table->index(['category', 'responded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('professional_evaluations');
    }
};
