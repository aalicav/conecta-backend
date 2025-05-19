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
            $table->integer('negotiation_cycle')->default(1)->after('status');
            $table->integer('max_cycles_allowed')->default(3)->after('negotiation_cycle');
            $table->json('previous_cycles_data')->nullable()->after('max_cycles_allowed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('negotiations', function (Blueprint $table) {
            $table->dropColumn(['negotiation_cycle', 'max_cycles_allowed', 'previous_cycles_data']);
        });
    }
};
