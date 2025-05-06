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
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('cpf')->unique();
            $table->date('birth_date');
            $table->string('gender');
            $table->string('health_card_number')->nullable();
            $table->unsignedBigInteger('health_plan_id');
            $table->string('address');
            $table->string('city');
            $table->string('state');
            $table->string('postal_code');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('location_consent')->default(false);
            $table->string('photo')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('health_plan_id')->references('id')->on('health_plans')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
}; 