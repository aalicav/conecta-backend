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
        Schema::table('medical_specialties', function (Blueprint $table) {
            $table->string('city')->nullable()->after('default_price');
            $table->string('state', 2)->nullable()->after('city');
            $table->index(['city', 'state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_specialties', function (Blueprint $table) {
            $table->dropIndex(['city', 'state']);
            $table->dropColumn(['city', 'state']);
        });
    }
}; 