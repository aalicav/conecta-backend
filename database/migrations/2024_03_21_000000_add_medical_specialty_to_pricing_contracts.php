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
        Schema::table('pricing_contracts', function (Blueprint $table) {
            $table->foreignId('medical_specialty_id')
                ->nullable()
                ->after('tuss_procedure_id')
                ->constrained('medical_specialties')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pricing_contracts', function (Blueprint $table) {
            $table->dropForeign(['medical_specialty_id']);
            $table->dropColumn('medical_specialty_id');
        });
    }
}; 