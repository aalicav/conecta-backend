<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->default('general');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->string('data_type')->default('string')->comment('string, boolean, integer, array, json');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });

        // Insert default settings for automatic scheduling
        DB::table('system_settings')->insert([
            [
                'key' => 'scheduling_enabled',
                'value' => 'true',
                'group' => 'scheduling',
                'description' => 'Enable or disable automatic scheduling',
                'is_public' => true,
                'data_type' => 'boolean',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'key' => 'scheduling_priority',
                'value' => 'balanced',
                'group' => 'scheduling',
                'description' => 'Priority for automatic scheduling (cost, distance, availability, balanced)',
                'is_public' => false,
                'data_type' => 'string',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'key' => 'scheduling_min_days',
                'value' => '2',
                'group' => 'scheduling',
                'description' => 'Minimum days of advance notice for automatic scheduling',
                'is_public' => false,
                'data_type' => 'integer',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'key' => 'scheduling_allow_manual_override',
                'value' => 'true',
                'group' => 'scheduling',
                'description' => 'Allow manual override of automatic scheduling',
                'is_public' => false,
                'data_type' => 'boolean',
                'created_at' => now(),
                'updated_at' => now()
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
}; 