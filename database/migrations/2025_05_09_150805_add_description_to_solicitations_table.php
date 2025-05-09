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
            // Verificar se a coluna já existe antes de adicioná-la
            if (!Schema::hasColumn('solicitations', 'description')) {
                $table->text('description')->nullable()->after('notes');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solicitations', function (Blueprint $table) {
            // Remover a coluna apenas se ela existir
            if (Schema::hasColumn('solicitations', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
