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
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            // Adicionar campo para nome do template
            
            // Renomear campo message para content (se necessário)
            if (Schema::hasColumn('whatsapp_messages', 'message') && !Schema::hasColumn('whatsapp_messages', 'content')) {
                $table->renameColumn('message', 'content');
            } elseif (!Schema::hasColumn('whatsapp_messages', 'content')) {
                $table->text('content')->nullable()->after('recipient');
            }
            
            // Adicionar campo para ID da mensagem no provedor
            if (!Schema::hasColumn('whatsapp_messages', 'provider_message_id')) {
                $table->string('provider_message_id')->nullable()->after('status');
            }
            
            // Adicionar campo para armazenar tipo de mídia
            if (!Schema::hasColumn('whatsapp_messages', 'media_type')) {
                $table->string('media_type')->nullable()->after('media_url');
            }            
            
            $table->string('template_name')->nullable()->after('media_type');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            // Remover campo para nome do template
            $table->dropColumn('template_name');
            
            // Outras operações de reversão podem ser adicionadas conforme necessário
            // Tomar cuidado com a remoção de colunas que podem já existir em outras versões do schema
        });
    }
};
