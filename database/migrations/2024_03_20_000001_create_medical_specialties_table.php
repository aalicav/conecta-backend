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
        Schema::create('medical_specialties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tuss_code', 20)->unique();
            $table->string('tuss_description');
            $table->boolean('negotiable')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Índices para performance
            $table->index('tuss_code');
            $table->index('active');
            $table->index('negotiable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_specialties');
    }
}; 