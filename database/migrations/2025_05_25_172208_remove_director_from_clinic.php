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
        Schema::table('clinics', function (Blueprint $table) {
            $table->dropColumn('technical_director');
            $table->dropColumn('technical_director_document');
            $table->dropColumn('technical_director_professional_id');
            $table->dropIndex("clinics_parent_clinic_id_foreign");
            $table->dropColumn('parent_clinic_id');
            $table->dropColumn('address');
            $table->dropColumn('city');
            $table->dropColumn('state');
            $table->dropColumn('postal_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
