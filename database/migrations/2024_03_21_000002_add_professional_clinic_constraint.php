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
        // First drop the existing constraint if it exists
        DB::statement('ALTER TABLE professional_availabilities DROP CONSTRAINT IF EXISTS check_professional_or_clinic');

        // Add the new constraint
        DB::statement('ALTER TABLE professional_availabilities ADD CONSTRAINT check_professional_or_clinic CHECK ((professional_id IS NOT NULL AND clinic_id IS NULL) OR (professional_id IS NULL AND clinic_id IS NOT NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the constraint
        DB::statement('ALTER TABLE professional_availabilities DROP CONSTRAINT IF EXISTS check_professional_or_clinic');
    }
}; 