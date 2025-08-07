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
            // Remove the old unique constraint
            $table->dropUnique('unique_health_plan_procedure');
            
            // Add the new unique constraint that includes medical_specialty_id
            $table->unique(['health_plan_id', 'tuss_procedure_id', 'medical_specialty_id'], 'unique_health_plan_procedure_specialty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('health_plan_procedures', function (Blueprint $table) {
            // Remove the new unique constraint
            $table->dropUnique('unique_health_plan_procedure_specialty');
            
            // Restore the old unique constraint
            $table->unique(['health_plan_id', 'tuss_procedure_id'], 'unique_health_plan_procedure');
        });
    }
}; 