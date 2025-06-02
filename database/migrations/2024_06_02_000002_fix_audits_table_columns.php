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
            // Adicionar coluna action se não existir
            if (!Schema::hasColumn('audits', 'action')) {
                $table->string('action')->nullable()->after('user_id');
            }

            // Adicionar coluna event se não existir
            if (!Schema::hasColumn('audits', 'event')) {
                $table->string('event')->nullable()->after('user_id');
            }

            // Adicionar coluna user_type se não existir
            if (!Schema::hasColumn('audits', 'user_type')) {
                $table->string('user_type')->nullable()->after('user_id');
            }
        });

        // Atualizar registros existentes para ter valores padrão
        DB::table('audits')->whereNull('event')->update(['event' => DB::raw('`action`')]);
        DB::table('audits')->whereNull('action')->update(['action' => DB::raw('`event`')]);
        
        // Tornar as colunas não nulas após a migração dos dados
        Schema::table('audits', function (Blueprint $table) {
            $table->string('event')->nullable(false)->change();
            $table->string('action')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->string('event')->nullable()->change();
            $table->string('action')->nullable()->change();
        });
    }
}; 