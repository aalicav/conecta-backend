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
        Schema::table('patients', function (Blueprint $table) {
            // Verificar se as colunas já existem antes de adicioná-las
            if (!Schema::hasColumn('patients', 'email')) {
                $table->string('email')->nullable()->after('photo');
            }
            
            if (!Schema::hasColumn('patients', 'secondary_contact_name')) {
                $table->string('secondary_contact_name')->nullable()->after('email');
            }
            
            if (!Schema::hasColumn('patients', 'secondary_contact_phone')) {
                $table->string('secondary_contact_phone')->nullable()->after('secondary_contact_name');
            }
            
            if (!Schema::hasColumn('patients', 'secondary_contact_relationship')) {
                $table->string('secondary_contact_relationship')->nullable()->after('secondary_contact_phone');
            }
            
            // Removemos a alteração do health_card_number para evitar erros com dados existentes
            // Vamos implementar a obrigatoriedade via validação no controller
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            // Remover as colunas apenas se elas existirem
            if (Schema::hasColumn('patients', 'email')) {
                $table->dropColumn('email');
            }
            
            if (Schema::hasColumn('patients', 'secondary_contact_name')) {
                $table->dropColumn('secondary_contact_name');
            }
            
            if (Schema::hasColumn('patients', 'secondary_contact_phone')) {
                $table->dropColumn('secondary_contact_phone');
            }
            
            if (Schema::hasColumn('patients', 'secondary_contact_relationship')) {
                $table->dropColumn('secondary_contact_relationship');
            }
            
            // Removemos a alteração do health_card_number
        });
    }
};
