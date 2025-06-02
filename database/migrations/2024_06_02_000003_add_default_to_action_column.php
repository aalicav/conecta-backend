<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            // Primeiro tornar a coluna nullable para permitir a alteração
            $table->string('action')->nullable()->change();
            
            // Atualizar registros existentes
            DB::statement('UPDATE audits SET action = event WHERE action IS NULL');
            
            // Adicionar valor padrão e tornar não nullable novamente
            $table->string('action')->default('created')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->string('action')->nullable()->default(null)->change();
        });
    }
}; 