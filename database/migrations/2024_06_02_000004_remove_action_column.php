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
        Schema::table('audits', function (Blueprint $table) {
            // Remover a coluna action se ela existir
            if (Schema::hasColumn('audits', 'action')) {
                $table->dropColumn('action');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            // Recriar a coluna action
            if (!Schema::hasColumn('audits', 'action')) {
                $table->string('action')->after('user_id');
            }
        });
    }
}; 