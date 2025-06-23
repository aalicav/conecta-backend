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
        Schema::table('reports', function (Blueprint $table) {
            $table->boolean('is_scheduled')->default(false)->after('is_template');
            $table->string('schedule_frequency')->nullable()->after('is_scheduled');
            $table->json('recipients')->nullable()->after('schedule_frequency');
            $table->timestamp('last_generated_at')->nullable()->after('recipients');
            $table->boolean('is_active')->default(true)->after('last_generated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn([
                'is_scheduled',
                'schedule_frequency',
                'recipients',
                'last_generated_at',
                'is_active'
            ]);
        });
    }
}; 