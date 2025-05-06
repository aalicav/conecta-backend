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
        Schema::create('clinics', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('cnpj')->unique();
            $table->text('description')->nullable();
            $table->string('cnes')->nullable()->comment('National Register of Health Establishments');
            $table->string('technical_director');
            $table->string('technical_director_document');
            $table->string('technical_director_professional_id');
            $table->unsignedBigInteger('parent_clinic_id')->nullable()->comment('For branch clinics');
            $table->string('address');
            $table->string('city');
            $table->string('state');
            $table->string('postal_code');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('logo')->nullable();
            $table->string('status')->default('pending')->comment('pending, approved, rejected');
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->boolean('has_signed_contract')->default(false);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('parent_clinic_id')->references('id')->on('clinics')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinics');
    }
}; 