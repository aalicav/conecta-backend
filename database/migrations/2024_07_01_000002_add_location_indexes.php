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
        // Add indexes to clinics table
        Schema::table('clinics', function (Blueprint $table) {
            $table->index('state');
            $table->index(['state', 'city']);
        });

        // Add indexes to professionals table
        Schema::table('professionals', function (Blueprint $table) {
            $table->index('state');
            $table->index(['state', 'city']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove indexes from clinics table
        Schema::table('clinics', function (Blueprint $table) {
            $table->dropIndex(['state']);
            $table->dropIndex(['state', 'city']);
        });

        // Remove indexes from professionals table
        Schema::table('professionals', function (Blueprint $table) {
            $table->dropIndex(['state']);
            $table->dropIndex(['state', 'city']);
        });
    }
}; 