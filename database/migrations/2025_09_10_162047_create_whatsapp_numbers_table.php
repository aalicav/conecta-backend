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
        Schema::create('whatsapp_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Nome identificador do número
            $table->string('phone_number')->unique(); // Número do WhatsApp
            $table->string('instance_id')->unique(); // ID da instância no Whapi
            $table->string('token'); // Token de acesso
            $table->string('type')->default('default'); // default, health_plan, professional, clinic
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->json('settings')->nullable(); // Configurações adicionais
            $table->timestamps();
        });

        // Tabela de associação entre planos de saúde e números do WhatsApp
        Schema::create('health_plan_whatsapp_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('health_plan_id')->constrained('health_plans')->onDelete('cascade');
            $table->foreignId('whatsapp_number_id')->constrained('whatsapp_numbers')->onDelete('cascade');
            $table->timestamps();
            
            $table->unique(['health_plan_id', 'whatsapp_number_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('health_plan_whatsapp_numbers');
        Schema::dropIfExists('whatsapp_numbers');
    }
};