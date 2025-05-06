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
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('autentique_document_id')->nullable()->after('created_by');
            $table->json('autentique_data')->nullable()->after('autentique_document_id');
            $table->json('autentique_webhook_data')->nullable()->after('autentique_data');
            $table->string('signed_file_path')->nullable()->after('file_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('autentique_document_id');
            $table->dropColumn('autentique_data');
            $table->dropColumn('autentique_webhook_data');
            $table->dropColumn('signed_file_path');
        });
    }
}; 