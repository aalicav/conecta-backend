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
        Schema::table('health_plans', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('user_id')
                  ->constrained('health_plans')
                  ->onDelete('set null');
            $table->string('parent_relation_type')->nullable()->after('parent_id')
                  ->comment('Type of parent relationship: subsidiary, franchise, branch, etc.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('health_plans', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['parent_id', 'parent_relation_type']);
        });
    }
};
