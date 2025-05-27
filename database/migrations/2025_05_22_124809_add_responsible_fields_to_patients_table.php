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
            if (!Schema::hasColumn('patients', 'responsible_name')) {
                $table->string('responsible_name')->nullable()->after('secondary_contact_relationship');
            }
            
            if (!Schema::hasColumn('patients', 'responsible_email')) {
                $table->string('responsible_email')->nullable()->after('responsible_name');
            }
            
            if (!Schema::hasColumn('patients', 'responsible_phone')) {
                $table->string('responsible_phone')->nullable()->after('responsible_email');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            if (Schema::hasColumn('patients', 'responsible_name')) {
                $table->dropColumn('responsible_name');
            }
            
            if (Schema::hasColumn('patients', 'responsible_email')) {
                $table->dropColumn('responsible_email');
            }
            
            if (Schema::hasColumn('patients', 'responsible_phone')) {
                $table->dropColumn('responsible_phone');
            }
        });
    }
};
