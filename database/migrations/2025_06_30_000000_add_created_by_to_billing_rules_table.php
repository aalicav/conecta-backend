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
        Schema::table('billing_rules', function (Blueprint $table) {
            if (!Schema::hasColumn('billing_rules', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->after('is_active');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_rules', function (Blueprint $table) {
            if (Schema::hasColumn('billing_rules', 'created_by')) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            }
        });
    }
}; 