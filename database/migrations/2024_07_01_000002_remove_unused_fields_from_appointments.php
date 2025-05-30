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
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'preferred_date',
                'preferred_time',
                'preferred_location_lat',
                'preferred_location_lng',
                'max_distance_km',
                'notes'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dateTime('preferred_date')->nullable();
            $table->time('preferred_time')->nullable();
            $table->decimal('preferred_location_lat', 10, 8)->nullable();
            $table->decimal('preferred_location_lng', 11, 8)->nullable();
            $table->decimal('max_distance_km', 5, 2)->nullable();
            $table->text('notes')->nullable();
        });
    }
}; 