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
        Schema::table('negotiations', function (Blueprint $table) {
            $table->foreignId('parent_negotiation_id')->nullable()->after('id');
            $table->boolean('is_fork')->default(false)->after('status');
            $table->timestamp('forked_at')->nullable()->after('is_fork');
            $table->integer('fork_count')->default(0)->after('forked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('negotiations', function (Blueprint $table) {
            $table->dropColumn(['parent_negotiation_id', 'is_fork', 'forked_at', 'fork_count']);
        });
    }
};
