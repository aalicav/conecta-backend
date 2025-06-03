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
        Schema::table('solicitations', function (Blueprint $table) {
            // Adicionar novos campos
            $table->string('state')->nullable()->after('description');
            $table->string('city')->nullable()->after('state');
            
            // Remover campos não utilizados
            $table->dropColumn([
                'secondary_contact_name',
                'secondary_contact_phone',
                'secondary_contact_relationship'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solicitations', function (Blueprint $table) {
            // Reverter adição dos novos campos
            $table->dropColumn(['state', 'city']);
            
            // Restaurar campos removidos
            $table->string('secondary_contact_name')->nullable();
            $table->string('secondary_contact_phone')->nullable();
            $table->string('secondary_contact_relationship')->nullable();
        });
    }
}; 