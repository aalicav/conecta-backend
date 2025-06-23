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
        Schema::table('extemporaneous_negotiations', function (Blueprint $table) {
            // Remove foreign key constraint if it exists
            $table->dropForeign(['contract_id']);
            // Drop the column
            $table->dropColumn('contract_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('extemporaneous_negotiations', function (Blueprint $table) {
            // Add the column back
            $table->foreignId('contract_id')->after('solicitation_id');
            // Add foreign key constraint back
            $table->foreign('contract_id')->references('id')->on('contracts');
        });
    }
}; 