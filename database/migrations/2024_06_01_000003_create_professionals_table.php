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
        Schema::create('professionals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('cpf')->unique();
            $table->date('birth_date');
            $table->string('gender')->nullable();
            $table->string('professional_type')->comment('Médico, Psicólogo, etc');
            $table->string('council_type')->comment('CRM, CRP, etc');
            $table->string('council_number');
            $table->string('council_state');
            $table->string('specialty')->nullable();
            $table->unsignedBigInteger('clinic_id')->nullable();
            $table->string('address');
            $table->string('city');
            $table->string('state');
            $table->string('postal_code');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('photo')->nullable();
            $table->string('status')->default('pending')->comment('pending, approved, rejected');
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->boolean('has_signed_contract')->default(false);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('clinic_id')->references('id')->on('clinics')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('professionals');
    }
}; 