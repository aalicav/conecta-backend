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
        Schema::table('health_plan_procedures', function (Blueprint $table) {
            $table->string('state', 2)->nullable()->after('medical_specialty_id');
            $table->string('city', 100)->nullable()->after('state');
            $table->index(['state', 'city']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('health_plan_procedures', function (Blueprint $table) {
            $table->dropIndex(['state', 'city']);
            $table->dropColumn(['state', 'city']);
        });
    }
}; 