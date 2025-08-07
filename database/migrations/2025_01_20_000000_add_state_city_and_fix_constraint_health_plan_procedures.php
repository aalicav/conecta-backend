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
            
            // Drop the old unique constraint if it exists
            if (Schema::hasIndex('health_plan_procedures', 'unique_health_plan_procedure_specialty')) {
                $table->dropUnique('unique_health_plan_procedure_specialty');
            }
            
            // Add the new unique constraint that includes state and city
            $table->unique(['health_plan_id', 'tuss_procedure_id', 'medical_specialty_id', 'state', 'city'], 'unique_health_plan_procedure_specialty_location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('health_plan_procedures', function (Blueprint $table) {
            // Drop the new unique constraint
            $table->dropUnique('unique_health_plan_procedure_specialty_location');
            
            // Restore the old unique constraint
            $table->unique(['health_plan_id', 'tuss_procedure_id', 'medical_specialty_id'], 'unique_health_plan_procedure_specialty');
            
            // Drop the index
            $table->dropIndex(['state', 'city']);
            
            // Drop the columns
            $table->dropColumn(['state', 'city']);
        });
    }
}; 