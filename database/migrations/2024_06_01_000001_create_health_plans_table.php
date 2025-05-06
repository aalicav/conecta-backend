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
        Schema::create('health_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('cnpj')->unique();
            $table->string('ans_code')->nullable()->comment('ANS registration code');
            $table->text('description')->nullable();
            $table->string('legal_representative_name');
            $table->string('legal_representative_cpf');
            $table->string('legal_representative_position');
            $table->string('address');
            $table->string('city');
            $table->string('state');
            $table->string('postal_code');
            $table->string('logo')->nullable();
            $table->string('status')->default('pending')->comment('pending, approved, rejected');
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->boolean('has_signed_contract')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('health_plans');
    }
}; 