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
        Schema::table('clinics', function (Blueprint $table) {
            $columns = [
                'technical_director',
                'technical_director_document',
                'technical_director_professional_id',
                'parent_clinic_id',
                'address',
                'city',
                'state',
                'postal_code'
            ];

            // Verifica e remove a foreign key se existir
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME
                FROM information_schema.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'clinics'
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                AND CONSTRAINT_NAME = 'clinics_parent_clinic_id_foreign'
            ");

            if (!empty($foreignKeys)) {
                $table->dropForeign('clinics_parent_clinic_id_foreign');
            }

            // Remove cada coluna se existir
            foreach ($columns as $column) {
                if (Schema::hasColumn('clinics', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->string('technical_director')->nullable();
            $table->string('technical_director_document')->nullable();
            $table->string('technical_director_professional_id')->nullable();
            $table->unsignedBigInteger('parent_clinic_id')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();

            $table->foreign('parent_clinic_id')
                  ->references('id')
                  ->on('clinics')
                  ->onDelete('set null');
        });
    }
};
