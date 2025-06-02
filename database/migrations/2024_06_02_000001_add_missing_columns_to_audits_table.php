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
            // Primeiro, vamos verificar se as colunas existem antes de adicioná-las
            if (!Schema::hasColumn('audits', 'action')) {
                $table->string('action')->after('user_id');
            }
            
            if (!Schema::hasColumn('audits', 'old_values')) {
                $table->json('old_values')->nullable()->after('action');
            }
            
            if (!Schema::hasColumn('audits', 'new_values')) {
                $table->json('new_values')->nullable()->after('old_values');
            }
            
            if (!Schema::hasColumn('audits', 'url')) {
                $table->string('url')->nullable()->after('auditable_id');
            }
            
            if (!Schema::hasColumn('audits', 'ip_address')) {
                $table->string('ip_address')->nullable()->after('url');
            }
            
            if (!Schema::hasColumn('audits', 'user_agent')) {
                $table->string('user_agent')->nullable()->after('ip_address');
            }
            
            if (!Schema::hasColumn('audits', 'tags')) {
                $table->json('tags')->nullable()->after('user_agent');
            }
            
            if (!Schema::hasColumn('audits', 'custom_message')) {
                $table->text('custom_message')->nullable()->after('tags');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->dropColumn([
                'action',
                'old_values',
                'new_values',
                'url',
                'ip_address',
                'user_agent',
                'tags',
                'custom_message'
            ]);
        });
    }
}; 