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
        Schema::table('negotiation_items', function (Blueprint $table) {
            $table->foreignId('medical_specialty_id')
                  ->nullable()
                  ->after('tuss_id')
                  ->constrained('medical_specialties')
                  ->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('negotiation_items', function (Blueprint $table) {
            $table->dropForeign(['medical_specialty_id']);
            $table->dropColumn('medical_specialty_id');
        });
    }
}; 